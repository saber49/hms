<?php

namespace App\Support;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Api\AufgabenController;
use App\Models\CentralUser;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Modul (Spezifikation „Interessentenmanagement“, 27.10.2026) —
 * single place for lists, visibility, the change log and the automation
 * rules that run inside a request (A3, A4, A5, A6, A7, A10, A11).
 *
 * Rights (spec §6) on top of PermissionRegistry:
 *   view       stammdaten.interessenten.view       Vertrieb: all firms/contacts, own leads & deals
 *   edit       stammdaten.interessenten.edit       own leads, deals, activities
 *   all        stammdaten.interessenten.view_all   Vertriebsleitung: everything, reassign, lifecycle reset
 *   uebergabe  stammdaten.interessenten.uebergabe  Objektleitung: won deals of own Sparte (BereichScope)
 *   admin      stammdaten.interessenten.admin      lists, deadlines, soft delete, export
 *              (derived: Inhaber / Administrator only — never grantable)
 *   leads|deals|kontakte (+ _edit)  crm.<section>.view|edit — per-section rights;
 *              view/edit above are derived as "any section".
 * Leads/deals are additionally limited to the user's Bereich whitelist (Sparte).
 *
 * No static caches: Octane reuses the process across tenants.
 */
final class Crm
{
    public const LEAD_OFFEN = ['neu', 'kontaktversuch', 'verbunden'];
    public const LIFECYCLE_MANUELL = ['interessent', 'mql', 'sql', 'ehemaliger_kunde'];

    /** Fields required when a deal ENTERS the phase (cumulative along the list order). */
    public const PHASE_PFLICHT = [
        'besichtigung_geplant' => ['objektadresse', 'besichtigung_am'],
        'besichtigung_erfolgt' => ['besichtigung_notiz'],
        'angebot_versendet'    => ['wert_monatlich_eur', 'laufzeit_monate', 'angebot_datei_pfad'],
        'verhandlung'          => [],
        'gewonnen'             => ['vertragsbeginn'],
        'verloren'             => ['verlust_grund'],
    ];
    public const FELD_LABEL = [
        'objektadresse' => 'Objektadresse', 'besichtigung_am' => 'Besichtigungstermin',
        'besichtigung_notiz' => 'Besichtigungsnotiz', 'wert_monatlich_eur' => 'Monatlicher Wert',
        'laufzeit_monate' => 'Laufzeit (Monate)', 'angebot_datei_pfad' => 'Angebotsdokument',
        'vertragsbeginn' => 'Vertragsbeginn', 'verlust_grund' => 'Verlustgrund',
    ];

    private const PERM = [
        'view' => 'stammdaten.interessenten.view', 'edit' => 'stammdaten.interessenten.edit',
        'all' => 'stammdaten.interessenten.view_all', 'uebergabe' => 'stammdaten.interessenten.uebergabe',
        'admin' => 'stammdaten.interessenten.admin',
        // Per-section rights (Benutzerverwaltung → Vertrieb).
        'leads' => 'crm.leads.view', 'leads_edit' => 'crm.leads.edit',
        'deals' => 'crm.deals.view', 'deals_edit' => 'crm.deals.edit',
        'kontakte' => 'crm.kontakte.view', 'kontakte_edit' => 'crm.kontakte.edit',
    ];

    public static function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    /**
     * Request-scoped memo. Lives on the current Request object (Octane gives
     * every request a fresh one) and is keyed by the tenant database, so it
     * can never serve another tenant's or a previous request's data.
     */
    public static function memo(string $key, callable $fn)
    {
        $bag = request()->attributes;
        $k = 'crm.' . DB::connection('tenant')->getDatabaseName() . '.' . $key;
        if (!$bag->has($k)) $bag->set($k, $fn());
        return $bag->get($k);
    }

    /** Drop memoized lists/settings after a write in the same request. */
    public static function forget(): void
    {
        $bag = request()->attributes;
        foreach (array_keys($bag->all()) as $k) {
            if (str_starts_with($k, 'crm.')) $bag->remove($k);
        }
    }

    public static function ready(): bool
    {
        return self::memo('ready', fn () => Schema::connection('tenant')->hasTable('crm_leads'));
    }

    public static function can($u, string $right): bool
    {
        return $u && method_exists($u, 'hasPermission') && $u->hasPermission(self::PERM[$right]);
    }

    /** Local calendar date for due/overdue logic (app timezone is UTC). */
    public static function heute(): string
    {
        return Carbon::now('Europe/Berlin')->toDateString();
    }

    // ---------------------------------------------------------------
    // Lists + settings
    // ---------------------------------------------------------------

    /** All entries of one list type, ordered, keyed by schluessel. */
    /** All list rows of the tenant, grouped by type (one query per request). */
    public static function alleListen(): Collection
    {
        return self::memo('listen', fn () => self::t('crm_listen')->orderBy('reihenfolge')->orderBy('id')->get()
            ->map(function ($r) {
                $r->zusatz = $r->zusatz ? (json_decode($r->zusatz, true) ?: []) : [];
                return $r;
            })->groupBy('typ'));
    }

    /** Entries of one list type, ordered, keyed by schluessel. */
    public static function liste(string $typ, bool $nurAktiv = false): Collection
    {
        $rows = self::alleListen()->get($typ, collect());
        if ($nurAktiv) $rows = $rows->where('aktiv', 1);
        return $rows->keyBy('schluessel');
    }

    public static function gueltig(string $typ, $key): bool
    {
        return is_string($key) && $key !== '' && (bool) (self::liste($typ, true)[$key] ?? null);
    }

    public static function setting(string $key, $default = null)
    {
        $v = self::memo('settings', fn () => self::t('crm_einstellungen')->pluck('wert', 'schluessel')->all())[$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    public static function intSetting(string $key, int $default): int
    {
        return max(0, (int) self::setting($key, $default));
    }

    /** Main Bereiche (= Sparten) the user may use. */
    public static function sparten($u): array
    {
        $all = self::memo('sparten', fn () => Schema::connection('tenant')->hasTable('bereiche_katalog')
            ? self::t('bereiche_katalog')->whereNull('parent_id')->where('aktiv', 1)->orderBy('sortierung')->orderBy('name')->pluck('name')->all()
            : []);
        $allowed = BereichScope::for($u);
        return is_array($allowed) ? array_values(array_filter($all, fn ($b) => BereichScope::listAllows($allowed, $b))) : $all;
    }

    public static function sparteGueltig($u, $sparte): bool
    {
        if (!is_string($sparte) || $sparte === '') return false;
        foreach (self::sparten($u) as $s) {
            if (mb_strtolower($s) === mb_strtolower($sparte)) return true;
        }
        return false;
    }

    // ---------------------------------------------------------------
    // Users
    // ---------------------------------------------------------------

    public static function userName($u): string
    {
        if (!$u) return 'Automatik';
        return trim((string) ($u->full_name ?? '')) ?: ((string) ($u->name ?? '') ?: (string) ($u->email ?? ''));
    }

    /** id => display name for a set of central user ids (one query). */
    public static function userNames(iterable $ids, int $tenantId): array
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        if (!$ids) return [];
        return DB::connection('mysql')->table('central_users')->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->get(['id', 'full_name', 'name', 'email'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => self::userName($r)])->all();
    }

    /** Active users of the tenant holding one of the given rights. */
    public static function usersWith(int $tenantId, array $rights): Collection
    {
        $users = self::memo('users.' . $tenantId, fn () => CentralUser::where('tenant_id', $tenantId)->where('aktiv', 1)
            ->where('name', 'not like', 'deleted\\_%')->where('name', '!=', 'demo-viewer')
            ->orderBy('full_name')->get());
        return $users->filter(fn ($x) => collect($rights)->contains(fn ($r) => self::can($x, $r)))->values();
    }

    /**
     * Owner for a new lead by the Sparte's assignment rule (spec §6):
     * rotation (round-robin over the pool) | region (PLZ ranges) | fest.
     * Pool members that are no longer active Vertrieb users are skipped.
     * Fallback: Vertriebsleitung (first non-owner with view_all), then $fallback.
     *
     * @return array{0:int, 1:string} [user id, rule used]
     */
    public static function zuweisen(?string $sparte, ?string $plz, int $tenantId, int $fallback): array
    {
        $valid = self::usersWith($tenantId, ['leads', 'all'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        $ok = fn ($id) => $id && in_array((int) $id, $valid, true);

        if ($sparte && Schema::connection('tenant')->hasTable('crm_zuweisung')) {
            $r = self::t('crm_zuweisung')->whereRaw('LOWER(sparte) = ?', [mb_strtolower($sparte)])->first();
            if ($r && $r->modus === 'fest' && $ok($r->fest_user_id)) return [(int) $r->fest_user_id, 'fest'];
            if ($r && $r->modus === 'region' && $plz) {
                $p = preg_replace('/\D/', '', $plz);
                foreach (json_decode((string) $r->plz_regeln, true) ?: [] as $reg) {
                    $von = str_pad(preg_replace('/\D/', '', (string) ($reg['von'] ?? '')), 5, '0');
                    $bis = str_pad(preg_replace('/\D/', '', (string) ($reg['bis'] ?? '')), 5, '9');
                    $pp = str_pad($p, 5, '0');
                    if ($p !== '' && $pp >= $von && $pp <= $bis && $ok($reg['user_id'] ?? null)) return [(int) $reg['user_id'], 'region'];
                }
            }
            if ($r && $r->modus === 'rotation') {
                $pick = DB::connection('tenant')->transaction(function () use ($r, $ok) {
                    $row = self::t('crm_zuweisung')->where('id', $r->id)->lockForUpdate()->first();
                    $pool = array_values(array_filter(array_map('intval', json_decode((string) $row->user_ids, true) ?: []), $ok));
                    if (!$pool) return null;
                    $idx = (int) $row->rr_index % count($pool);
                    self::t('crm_zuweisung')->where('id', $r->id)->update(['rr_index' => $idx + 1, 'updated_at' => now()]);
                    return $pool[$idx];
                });
                if ($pick) return [$pick, 'rotation'];
            }
        }
        $leitung = self::usersWith($tenantId, ['all'])->first(fn ($x) => !$x->is_owner && $ok($x->id));
        return $leitung ? [(int) $leitung->id, 'fallback'] : [$fallback, 'fallback'];
    }

    /** null = ok, false = not an active Vertrieb user of this tenant. */
    public static function ownerGueltig($u, $id): bool
    {
        if (!is_numeric($id)) return false;
        return self::usersWith((int) $u->tenant_id, ['view', 'all'])->contains(fn ($x) => (int) $x->id === (int) $id);
    }

    // ---------------------------------------------------------------
    // Visibility
    // ---------------------------------------------------------------

    private static function sparteScope($q, $u, string $col): void
    {
        $allowed = BereichScope::for($u);
        if ($allowed === null) return;
        if (empty($allowed)) { $q->whereRaw('1=0'); return; }
        // Untagged (migrated) rows stay visible to their owner only via the owner rule.
        $q->where(function ($w) use ($allowed, $col) {
            $w->whereNull($col)->orWhereIn(DB::raw("LOWER($col)"), $allowed);
        });
    }

    public static function scopeLeads($q, $u, string $a = 'l'): void
    {
        $q->whereNull("$a.deleted_at");
        if (!self::can($u, 'all')) $q->where("$a.owner_id", (int) $u->id);
        self::sparteScope($q, $u, "$a.sparte");
    }

    public static function scopeDeals($q, $u, string $a = 'd'): void
    {
        $q->whereNull("$a.deleted_at");
        if (!self::can($u, 'all')) {
            $own = self::can($u, 'view');
            $ueb = self::can($u, 'uebergabe');
            $q->where(function ($w) use ($u, $a, $own, $ueb) {
                if ($own) $w->orWhere("$a.owner_id", (int) $u->id);
                if ($ueb) $w->orWhere("$a.phase", 'gewonnen');
                if (!$own && !$ueb) $w->whereRaw('1=0');
            });
        }
        self::sparteScope($q, $u, "$a.sparte");
    }

    /** Visible leads joined with contact + firm and the next open linked task. */
    public static function leadsQuery($u)
    {
        $next = self::t('aufgaben as a')->join('aufgabe_empfaenger as e', 'e.aufgabe_id', '=', 'a.id')
            ->whereColumn('a.bezug_id', 'l.id')->where('a.bezug_typ', 'lead')->where('e.status', '!=', 'erledigt')
            ->selectRaw("MIN(CONCAT(a.faellig_am, ' ', COALESCE(a.faellig_zeit, '00:00:00')))");
        $q = self::t('crm_leads as l')
            ->join('crm_kontakte as k', 'k.id', '=', 'l.kontakt_id')
            ->leftJoin('crm_firmen as f', 'f.id', '=', 'l.firma_id')
            ->select('l.*', 'k.anrede', 'k.vorname', 'k.nachname', 'k.position', 'k.telefon', 'k.mobil', 'k.email',
                'k.quelle', 'k.lifecycle_stage', 'k.entscheider', 'f.name as firma_name', 'f.plz', 'f.ort')
            ->selectSub($next, 'naechste_aufgabe_am');
        self::scopeLeads($q, $u);
        return $q;
    }

    /** SQL fragments for "heute fällig" / "überfällig" (A8) — same rules as leadZeilen(). */
    private static function flagSql(): array
    {
        $offen = "x.stage IN ('" . implode("','", self::LEAD_OFFEN) . "')";
        $heute = self::heute();
        $grenze = now()->subDays(self::intSetting('a8_inaktiv_tage', 7))->format('Y-m-d H:i:s');
        return [
            "($offen AND LEFT(x.naechste_aufgabe_am, 10) = ?)", [$heute],
            "($offen AND (LEFT(x.naechste_aufgabe_am, 10) < ? OR COALESCE(x.letzte_aktivitaet_am, x.created_at) < ?))", [$heute, $grenze],
        ];
    }

    /** Visible leads as a derived table `x` with f_heute / f_ueber flags (filterable in SQL). */
    public static function leadFlagsQuery($u)
    {
        [$hSql, $hB, $uSql, $uB] = self::flagSql();
        return DB::connection('tenant')->query()->fromSub(self::leadsQuery($u), 'x')
            ->select('x.*')->selectRaw("$hSql AS f_heute", $hB)->selectRaw("$uSql AS f_ueber", $uB);
    }

    /** Tab counters over open visible leads — one aggregate query. */
    public static function leadZaehler($u): array
    {
        [$hSql, $hB, $uSql, $uB] = self::flagSql();
        $r = DB::connection('tenant')->query()
            ->fromSub(self::leadsQuery($u)->whereIn('l.stage', self::LEAD_OFFEN), 'x')
            ->selectRaw("COUNT(*) AS alle, SUM(x.owner_id = ?) AS meine, SUM(x.stage = 'neu') AS neu, SUM(x.stage = 'verbunden') AS verbunden,
                SUM($hSql) AS heute, SUM($uSql) AS ueberfaellig", array_merge([(int) $u->id], $hB, $uB))
            ->first();
        return [
            'meine' => (int) ($r->meine ?? 0), 'heute' => (int) ($r->heute ?? 0), 'ueberfaellig' => (int) ($r->ueberfaellig ?? 0),
            'neu' => (int) ($r->neu ?? 0), 'verbunden' => (int) ($r->verbunden ?? 0),
            'alle' => self::can($u, 'all') ? (int) ($r->alle ?? 0) : null,
        ];
    }

    /** Derived fields for lead rows: names, days in stage, A8 overdue, due today. */
    public static function leadZeilen(iterable $rows, $u): Collection
    {
        $rows = collect($rows);
        $names = self::userNames($rows->pluck('owner_id'), (int) $u->tenant_id);
        $stages = self::liste('lead_stage');
        $heute = self::heute();
        $grenze = now()->subDays(self::intSetting('a8_inaktiv_tage', 7));
        return $rows->map(function ($r) use ($names, $stages, $heute, $grenze) {
            $offen = in_array($r->stage, self::LEAD_OFFEN, true);
            $naechste = $r->naechste_aufgabe_am ? substr($r->naechste_aufgabe_am, 0, 10) : null;
            $letzte = $r->letzte_aktivitaet_am ?: $r->created_at;
            $r->owner_name = $names[(int) $r->owner_id] ?? ('#' . $r->owner_id);
            $r->stage_name = $stages[$r->stage]->name ?? $r->stage;
            $r->offen = $offen;
            $r->kontakt_name = trim(($r->vorname ?? '') . ' ' . ($r->nachname ?? ''));
            $r->tage_in_stage = $r->stage_changed_at ? (int) Carbon::parse($r->stage_changed_at)->diffInDays(now()) : 0;
            $r->inaktiv = $offen && $letzte && Carbon::parse($letzte)->lt($grenze);
            $r->aufgabe_ueberfaellig = $offen && $naechste && $naechste < $heute;
            $r->ueberfaellig = $r->inaktiv || $r->aufgabe_ueberfaellig;
            $r->faellig_heute = $offen && $naechste === $heute;
            $r->checkliste = is_string($r->checkliste ?? null) ? (json_decode($r->checkliste, true) ?: []) : ($r->checkliste ?? []);
            return $r;
        });
    }

    public static function lead($u, string $id): ?object
    {
        $q = self::t('crm_leads as l')->where('l.id', $id);
        self::scopeLeads($q, $u);
        return $q->select('l.*')->first();
    }

    public static function deal($u, string $id): ?object
    {
        $q = self::t('crm_deals as d')->where('d.id', $id);
        self::scopeDeals($q, $u);
        return $q->select('d.*')->first();
    }

    /**
     * Edit = the section's edit right (leads|deals|kontakte; '' = any
     * section) AND (Vertriebsleitung OR owner of the row).
     */
    public static function darfBearbeiten($u, object $row, string $bereich = ''): bool
    {
        $edit = $bereich !== '' ? self::can($u, $bereich . '_edit') : self::can($u, 'edit');
        return $edit && (self::can($u, 'all') || (int) ($row->owner_id ?? 0) === (int) $u->id);
    }

    // ---------------------------------------------------------------
    // Change log (spec 2.7) — append-only
    // ---------------------------------------------------------------

    public static function log(string $objekt, string $id, string $feld, $alt, $neu, $u = null, ?string $regel = null): void
    {
        if ((string) $alt === (string) $neu) return;
        self::t('crm_protokoll')->insert([
            'objekt' => $objekt, 'objekt_id' => $id, 'feld' => $feld,
            'alt' => $alt === null ? null : mb_substr((string) $alt, 0, 250),
            'neu' => $neu === null ? null : mb_substr((string) $neu, 0, 250),
            'user_id' => $u->id ?? null,
            'user_name' => mb_substr($u ? self::userName($u) : 'Automatik' . ($regel ? ' ' . $regel : ''), 0, 200),
            'regel' => $regel ? mb_substr($regel, 0, 30) : null, 'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // State changes
    // ---------------------------------------------------------------

    public static function setLeadStage(object $lead, string $neu, $u, ?string $regel = null, array $extra = []): void
    {
        $upd = $extra + ['updated_at' => now()];
        if ($lead->stage !== $neu) {
            $upd['stage'] = $neu;
            $upd['stage_changed_at'] = now();
            self::log('lead', $lead->id, 'stage', $lead->stage, $neu, $regel ? null : $u, $regel);
            $lead->stage = $neu;
        }
        self::t('crm_leads')->where('id', $lead->id)->update($upd);
    }

    /**
     * Lifecycle moves forward only (spec §1). $reset = Vertriebsleitung/Admin
     * may move it backwards.
     */
    public static function setLifecycle(string $kontaktId, string $neu, $u, ?string $regel = null, bool $reset = false): bool
    {
        $k = self::t('crm_kontakte')->where('id', $kontaktId)->first();
        if (!$k || $k->lifecycle_stage === $neu) return false;
        $order = self::liste('lifecycle')->keys()->flip();
        $vorwaerts = ($order[$neu] ?? -1) > ($order[$k->lifecycle_stage] ?? -1);
        if (!$vorwaerts && !$reset) return false;
        self::t('crm_kontakte')->where('id', $kontaktId)->update([
            'lifecycle_stage' => $neu, 'lifecycle_changed_at' => now(), 'updated_at' => now(),
        ]);
        self::log('kontakt', $kontaktId, 'lifecycle_stage', $k->lifecycle_stage, $neu, $regel ? null : $u, $regel);
        return true;
    }

    public static function setOwner(string $table, string $objekt, object $row, int $neu, $u): void
    {
        if ((int) $row->owner_id === $neu) return;
        self::t($table)->where('id', $row->id)->update(['owner_id' => $neu, 'updated_at' => now()]);
        self::log($objekt, $row->id, 'owner_id', $row->owner_id, $neu, $u);
    }

    /**
     * Missing required fields for moving $deal (merged with $patch) into
     * $ziel — including every skipped phase on the way (spec 3.3).
     */
    public static function fehlendePflichtfelder(object $deal, array $patch, string $ziel): array
    {
        $order = self::liste('deal_phase')->keys()->values()->all();
        $phasen = $ziel === 'verloren' ? ['verloren'] : array_slice($order, 0, (array_search($ziel, $order, true) ?: 0) + 1);
        $phasen = array_values(array_filter($phasen, fn ($p) => $p !== 'verloren' || $ziel === 'verloren'));
        $merged = array_merge((array) $deal, $patch);
        $fehlt = [];
        foreach ($phasen as $p) {
            foreach (self::PHASE_PFLICHT[$p] ?? [] as $f) {
                $v = $merged[$f] ?? null;
                if ($v === null || $v === '' || ($f === 'wert_monatlich_eur' && (float) $v <= 0)) $fehlt[$f] = self::FELD_LABEL[$f] . ' ist Pflicht';
            }
        }
        if ($ziel === 'verloren' && ($merged['verlust_grund'] ?? null) === 'sonstiges' && trim((string) ($merged['verlust_notiz'] ?? '')) === '') {
            $fehlt['verlust_notiz'] = 'Notiz ist bei „Sonstiges“ Pflicht';
        }
        return $fehlt;
    }

    // ---------------------------------------------------------------
    // Automation inside the request
    // ---------------------------------------------------------------

    /**
     * A4 / A5 after an activity was logged on a lead.
     *   A4 outgoing call/e-mail: attempt counter +1; Neu → Kontaktversuch
     *   A5 call "erreicht" or any incoming call/e-mail: Neu|Kontaktversuch → Verbunden
     */
    public static function nachAktivitaet(object $lead, object $akt, $u): void
    {
        $extra = ['letzte_aktivitaet_am' => $akt->zeitpunkt];
        if (!in_array($lead->stage, self::LEAD_OFFEN, true)) {
            self::t('crm_leads')->where('id', $lead->id)->update($extra + ['updated_at' => now()]);
            return;
        }
        $kontaktArt = in_array($akt->typ, ['anruf', 'email'], true);
        $ausgehend = $kontaktArt && $akt->richtung === 'ausgehend';
        $verbunden = ($akt->typ === 'anruf' && $akt->ergebnis === 'erreicht')
            || ($kontaktArt && $akt->richtung === 'eingehend');

        if ($ausgehend) {
            $extra['anzahl_kontaktversuche'] = (int) $lead->anzahl_kontaktversuche + 1;
        }
        if ($verbunden && in_array($lead->stage, ['neu', 'kontaktversuch'], true)) {
            self::setLeadStage($lead, 'verbunden', $u, 'A5', $extra);
        } elseif ($ausgehend && $lead->stage === 'neu') {
            self::setLeadStage($lead, 'kontaktversuch', $u, 'A4', $extra);
        } else {
            self::t('crm_leads')->where('id', $lead->id)->update($extra + ['updated_at' => now()]);
        }
    }

    /** A9 — "Nicht erreichbar" is selectable only after the threshold of attempts. */
    public static function nichtErreichbarErlaubt(object $lead): bool
    {
        $n = self::intSetting('a9_versuche', 6);
        $tage = self::intSetting('a9_tage', 21);
        $seit = now()->subDays($tage);
        $inFenster = self::t('crm_aktivitaeten')->where('lead_id', $lead->id)
            ->whereIn('typ', ['anruf', 'email'])->where('richtung', 'ausgehend')
            ->where('zeitpunkt', '>=', $seit)->count();
        return $inFenster >= $n || (int) $lead->anzahl_kontaktversuche >= $n;
    }

    /**
     * A1 — duplicate check: same e-mail / phone / mobile on a contact, or
     * same firm name + PLZ. Returns hits for the warning dialog.
     */
    /** Digits-only phone for duplicate matching: "+49 (345) 12-34" → "034512 34" → "0345123"… (+49/0049 → 0). */
    public static function telNorm($p): ?string
    {
        $raw = trim((string) $p);
        if ($raw === '') return null;
        $n = preg_replace('/\D/', '', $raw);
        if (str_starts_with($n, '0049')) $n = '0' . substr($n, 4);
        elseif (str_starts_with($raw, '+49')) $n = '0' . substr($n, 2);
        return strlen($n) >= 6 ? substr($n, 0, 30) : null;
    }

    public static function dubletten(array $d, ?string $ohneKontakt = null, ?string $ohneFirma = null): array
    {
        $treffer = [];
        $email = trim((string) ($d['email'] ?? ''));
        $phones = array_values(array_unique(array_filter([self::telNorm($d['telefon'] ?? ''), self::telNorm($d['mobil'] ?? '')])));
        if ($email !== '' || $phones) {
            // Index lookups only: e-mail (case-insensitive collation) + normalized phone columns.
            $q = self::t('crm_kontakte as k')->leftJoin('crm_firmen as f', 'f.id', '=', 'k.firma_id')
                ->whereNull('k.deleted_at')
                ->where(function ($w) use ($email, $phones) {
                    if ($email !== '') $w->orWhere('k.email', $email);
                    if ($phones) $w->orWhereIn('k.telefon_norm', $phones)->orWhereIn('k.mobil_norm', $phones);
                });
            if ($ohneKontakt) $q->where('k.id', '!=', $ohneKontakt);
            foreach ($q->limit(10)->get(['k.id', 'k.vorname', 'k.nachname', 'k.email', 'k.telefon', 'k.mobil', 'f.name as firma']) as $k) {
                $treffer[] = ['art' => 'kontakt', 'id' => $k->id, 'name' => trim($k->vorname . ' ' . $k->nachname), 'firma' => $k->firma,
                    'grund' => $email !== '' && mb_strtolower((string) $k->email) === mb_strtolower($email) ? 'E-Mail' : 'Telefon'];
            }
        }
        $fname = trim((string) ($d['firma_name'] ?? ''));
        $plz = trim((string) ($d['plz'] ?? ''));
        if ($fname !== '' && $plz !== '') {
            $q = self::t('crm_firmen')->whereNull('deleted_at')->where('plz', $plz)->where('name', $fname);
            if ($ohneFirma) $q->where('id', '!=', $ohneFirma);
            foreach ($q->limit(5)->get(['id', 'name', 'plz', 'ort']) as $f) {
                $treffer[] = ['art' => 'firma', 'id' => $f->id, 'name' => $f->name, 'firma' => trim($f->plz . ' ' . $f->ort), 'grund' => 'Firma + PLZ'];
            }
        }
        return $treffer;
    }

    /** Linked tasks of the given records (bezug_typ => ids), open first, with the assignee row. */
    public static function aufgabenFuer(array $bezug): Collection
    {
        $q = self::t('aufgaben as a')->join('aufgabe_empfaenger as e', 'e.aufgabe_id', '=', 'a.id')
            ->where('e.typ', 'user')
            ->where(function ($w) use ($bezug) {
                $any = false;
                foreach ($bezug as $typ => $ids) {
                    $ids = array_values(array_filter((array) $ids));
                    if ($ids) { $w->orWhere(fn ($x) => $x->where('a.bezug_typ', $typ)->whereIn('a.bezug_id', $ids)); $any = true; }
                }
                if (!$any) $w->whereRaw('1=0');
            })
            ->select('a.id', 'a.titel', 'a.typ', 'a.bezug_typ', 'a.bezug_id', 'a.faellig_am', 'a.faellig_zeit', 'a.beschreibung',
                'a.created_by_name', 'e.id as empfaenger_id', 'e.user_id', 'e.name as zugewiesen_name', 'e.status', 'e.erledigt_am');
        $heute = self::heute();
        return $q->orderByRaw("e.status = 'erledigt'")->orderBy('a.faellig_am')->orderBy('a.faellig_zeit')->limit(300)->get()
            ->map(function ($r) use ($heute) {
                $r->ueberfaellig = $r->status !== 'erledigt' && $r->faellig_am && $r->faellig_am < $heute;
                $r->heute = $r->status !== 'erledigt' && $r->faellig_am === $heute;
                return $r;
            });
    }

    /** Linked task in the existing Aufgaben module (bell + push + mail for others). */
    public static function aufgabe(string $bezugTyp, string $bezugId, string $typ, string $titel, string $datum, ?string $zeit,
                                   int $an, $creator, ?string $sparte = null, ?string $text = null, bool $notify = true): int
    {
        return AufgabenController::createLinked([
            'titel' => mb_substr($titel, 0, 200), 'beschreibung' => $text, 'bereich' => $sparte,
            'bezug_typ' => $bezugTyp, 'bezug_id' => $bezugId, 'typ' => $typ,
            'faellig_am' => $datum, 'faellig_zeit' => $zeit, 'prioritaet' => 'normal',
        ], $creator, $an, $notify);
    }

    /** Pseudo creator for tasks created by the automation (notifies the assignee, created_by NULL). */
    public static function automatik(int $tenantId): object
    {
        return (object) ['id' => null, 'tenant_id' => $tenantId, 'full_name' => 'Vertrieb-Automatik', 'name' => 'automatik', 'email' => null];
    }

    /** Bell + push to every user holding $right (optionally limited to a Sparte). */
    public static function hinweis(array $rights, string $title, string $body, string $link, ?string $sparte = null, ?int $ohne = null): void
    {
        try {
            $tenant = TenantService::current();
            if (!$tenant) return;
            $ids = self::usersWith((int) $tenant->id, $rights)
                ->filter(fn ($x) => !$sparte || BereichScope::userAllows($x, $sparte))
                ->pluck('id')->map(fn ($i) => (int) $i)->reject(fn ($i) => $i === $ohne)->values()->all();
            if ($ids) NotificationDispatcher::notifyUserIds((int) $tenant->id, $ids, 'vertrieb.hinweis', $title, $body, $link);
        } catch (\Throwable $e) {
            // best effort — never break the request
        }
    }

    public static function changed(): void
    {
        self::forget();
        \App\Helpers\Realtime::changed('crm');
    }

    // ---------------------------------------------------------------
    // Validation with German messages (the app ships no lang files)
    // ---------------------------------------------------------------

    /** German validation messages — shared with the QM module (App\Support\Qm\Qm::pruefe). */
    public const MSG = [
        'required' => ':attribute ist Pflicht.',
        'required_if' => ':attribute ist Pflicht.',
        'required_with' => ':attribute ist Pflicht.',
        'prohibited' => ':attribute kann hier nicht geändert werden.',
        'accepted' => ':attribute muss bestätigt werden.',
        'max' => ['string' => ':attribute: max. :max Zeichen.', 'numeric' => ':attribute: höchstens :max.', 'file' => ':attribute: max. :max KB.', 'array' => ':attribute: max. :max Einträge.'],
        'min' => ['string' => ':attribute: mind. :min Zeichen.', 'numeric' => ':attribute: mindestens :min.', 'file' => ':attribute: mind. :min KB.', 'array' => ':attribute: mind. :min Einträge.'],
        'email' => ':attribute ist keine gültige E-Mail-Adresse.',
        'date' => ':attribute ist kein gültiges Datum.',
        'date_format' => ':attribute: Format :format erwartet.',
        'after_or_equal' => ':attribute liegt zu früh.',
        'in' => ':attribute: ungültiger Wert.',
        'numeric' => ':attribute muss eine Zahl sein.',
        'integer' => ':attribute muss eine ganze Zahl sein.',
        'boolean' => ':attribute: ja/nein erwartet.',
        'array' => ':attribute: Liste erwartet.',
        'uuid' => ':attribute: ungültiger Verweis.',
        'file' => ':attribute: Datei erwartet.',
        'mimes' => ':attribute: erlaubte Dateitypen :values.',
        'regex' => ':attribute hat ein ungültiges Format.',
    ];
    private const ATTR = [
        'name' => 'Name', 'firma_name' => 'Firmenname', 'strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort',
        'bundesland' => 'Bundesland', 'branche' => 'Branche', 'mitarbeiterzahl' => 'Mitarbeiterzahl',
        'telefon' => 'Telefon', 'mobil' => 'Mobil', 'website' => 'Website', 'email' => 'E-Mail',
        'anrede' => 'Anrede', 'vorname' => 'Vorname', 'nachname' => 'Nachname', 'position' => 'Position',
        'quelle' => 'Quelle', 'owner_id' => 'Owner', 'sparte' => 'Sparte', 'prioritaet' => 'Priorität',
        'typ' => 'Typ', 'richtung' => 'Richtung', 'ergebnis' => 'Ergebnis', 'notiz' => 'Notiz',
        'zeitpunkt' => 'Zeitpunkt', 'titel' => 'Titel', 'faellig_am' => 'Fällig am', 'faellig_zeit' => 'Uhrzeit',
        'zugewiesen_an' => 'Zugewiesen an', 'grund' => 'Grund', 'wiedervorlage_am' => 'Wiedervorlage',
        'objektadresse' => 'Objektadresse', 'besichtigung_am' => 'Besichtigungstermin', 'besichtigung_notiz' => 'Besichtigungsnotiz',
        'wert_monatlich_eur' => 'Monatlicher Wert', 'laufzeit_monate' => 'Laufzeit', 'erwarteter_abschluss' => 'Erwarteter Abschluss',
        'vertragsbeginn' => 'Vertragsbeginn', 'verlust_grund' => 'Verlustgrund', 'verlust_notiz' => 'Notiz', 'phase' => 'Phase',
        'datei' => 'Datei', 'stage' => 'Stage', 'dsgvo_datum' => 'Einwilligungsdatum', 'wert' => 'Wert',
        'deal.titel' => 'Titel', 'deal.objektadresse' => 'Objektadresse', 'deal.besichtigung_am' => 'Besichtigungstermin',
        'deal.erwarteter_abschluss' => 'Erwarteter Abschluss', 'aufgabe.titel' => 'Titel der Aufgabe',
        'aufgabe.faellig_am' => 'Fälligkeitsdatum', 'aufgabe.faellig_zeit' => 'Uhrzeit', 'checkliste.termin_am' => 'Termin',
        'checkliste.termin_zeit' => 'Uhrzeit', 'firma.name' => 'Firmenname', 'firma.mitarbeiterzahl' => 'Mitarbeiterzahl',
        'kontakt_id' => 'Kontakt', 'firma_id' => 'Firma', 'bezug_id' => 'Bezug', 'duplikat_von' => 'Original-Lead',
        'kunde_anlegen' => 'Kunde anlegen', 'reihenfolge' => 'Reihenfolge', 'aktiv' => 'Aktiv', 'wahrscheinlichkeit' => 'Wahrscheinlichkeit',
    ];

    /** @return array{0: ?array, 1: array} [validated data | null, errors field => [msg]] */
    public static function pruefe(array $data, array $rules): array
    {
        $v = \Illuminate\Support\Facades\Validator::make($data, $rules, self::MSG, self::ATTR);
        return $v->fails() ? [null, $v->errors()->toArray()] : [$v->validated(), []];
    }

    public static function fehler(array $errors, int $status = 422)
    {
        return response()->json(['errors' => $errors, 'error' => collect($errors)->flatten()->first()], $status);
    }

    /** Store an upload on the PRIVATE disk; returns [disk, path, original name]. */
    public static function datei($file, string $ordner): array
    {
        $tenant = TenantService::current();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $dir = 'crm/' . ($tenant->id ?? 0) . '/' . $ordner;
        $name = \Illuminate\Support\Str::random(24) . '.' . $ext;
        $file->storeAs($dir, $name, 'local');
        return ['local', $dir . '/' . $name, mb_substr($file->getClientOriginalName(), 0, 250)];
    }

    public const DATEI_REGEL = 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx,xls,xlsx';
}
