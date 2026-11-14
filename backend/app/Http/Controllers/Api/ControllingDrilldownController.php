<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControllingAggregationService as Agg;
use App\Services\ControllingCockpitService as Cockpit;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controlling Drill-down
 * ----------------------
 * GET /api/controlling/drilldown/{dim}/{id}
 *
 * One endpoint that answers "tell me EVERYTHING about this <Kunde | Objekt |
 * NU | Artikel | Bereich | Schichtart | Mitarbeiter> inside the current
 * period + filters". The frontend opens it when the user clicks a row in
 * any Top-N card, and can drill further (e.g. Kunde → Objekt → Artikel).
 *
 * Payload
 *   entity        : {dim, id, name, stammdaten[{label,value}]}
 *   period/compare: same shape as /kpi
 *   kpi, kpi_prev, delta
 *   share         : entity revenue / total revenue in the period (%)
 *   status        : bestätigt / ausstehend / abgelehnt counts
 *   bestellungen  : orders touching the period (Kunde/Objekt/NU only)
 *   trend         : one bucket per period (continuous)
 *   breakdowns    : {objekte|kunden|artikel|nu|mitarbeiter|schichtart|bereich → rows[]}
 *   heatmap       : dow × month grid
 *   einsaetze     : the most recent shifts (with worker names + times)
 *
 * Every number is computed with the SAME SQL helpers as the dashboard so a
 * drill-down never contradicts the card the user clicked on.
 */
class ControllingDrilldownController extends Controller
{
    private const DIMS = ['kunde', 'objekt', 'nu', 'artikel', 'bereich', 'schichtart', 'mitarbeiter'];

    /** Sub-breakdowns that make sense for each entity type. */
    private const SUBDIMS = [
        'kunde' => ['objekte', 'artikel', 'nu', 'mitarbeiter', 'schichtart'],
        'objekt' => ['artikel', 'nu', 'mitarbeiter', 'schichtart'],
        'nu' => ['kunden', 'objekte', 'artikel', 'schichtart'],
        'artikel' => ['nu', 'mitarbeiter', 'schichtart'],
        'bereich' => ['kunden', 'objekte', 'artikel', 'nu', 'mitarbeiter'],
        'schichtart' => ['kunden', 'objekte', 'artikel', 'nu', 'mitarbeiter'],
        'mitarbeiter' => ['kunden', 'objekte', 'artikel', 'schichtart'],
    ];

    public function show(Request $request, string $dim, string $id)
    {
        if (!in_array($dim, self::DIMS, true)) {
            return response()->json(['error' => 'Unbekannte Dimension'], 422);
        }
        $id = trim(urldecode($id));
        if ($id === '') {
            return response()->json(['error' => 'ID fehlt'], 422);
        }

        // Bereich scope: the entity itself must be inside the caller's
        // whitelist, otherwise the Stammdaten header (address, contacts,
        // Stundenlohn) would leak even though all aggregates stay empty.
        if (!$this->entityInScope($request->user(), $dim, $id)) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }

        [$from, $to] = Agg::resolvePeriod($request);
        $filters = Agg::resolveFilters($request);
        $compare = $request->input('compare') === 'year' ? 'year' : 'prev';
        $granularity = Agg::whitelist((string) $request->input('granularity', Agg::autoGranularity($from, $to)),
            ['month', 'day', 'week', 'quarter', 'year']);
        $limit = min(300, max(10, (int) $request->input('limit', 100)));
        $basis = $request->input('basis') === 'abrechnung' ? 'abrechnung' : 'operativ';
        $settings = Cockpit::settings();
        $today = Cockpit::today();

        $cacheKey = Agg::cacheKey('drill2', compact('dim', 'id', 'from', 'to', 'filters', 'compare', 'granularity', 'limit', 'basis', 'settings', 'today'));
        $payload = Cache::remember($cacheKey, Agg::CACHE_TTL, function () use ($dim, $id, $from, $to, $filters, $compare, $granularity, $limit, $basis) {
            $this->ctx = Cockpit::context();
            $this->statuses = Cockpit::statusesFor($basis);
            return $this->build($dim, $id, $from, $to, $filters, $compare, $granularity, $limit) + ['basis' => $basis];
        });

        if (isset($payload['error'])) {
            return response()->json($payload, 404);
        }

        return Agg::jsonCached($payload);
    }

    // ============================================================

    private function build(string $dim, string $id, string $from, string $to, array $filters, string $compare, string $granularity, int $limit): array
    {
        $entity = $this->entity($dim, $id);
        if (!$entity) {
            return ['error' => 'Nicht gefunden'];
        }

        [$entWhere, $entParams] = $this->entityCondition($dim, $id);

        $kpi = $this->kpi($from, $to, $filters, $entWhere, $entParams);
        // Like-for-like comparison (same rule as the cockpit): a period that
        // reaches into the future is compared up to the same day.
        $cmp = Cockpit::comparison($from, $to, $compare);
        if ($cmp) {
            [$pFrom, $pTo] = [$cmp['from'], $cmp['to']];
            $kpiCur = $cmp['cur_to'] === $to ? $kpi : $this->kpi($from, $cmp['cur_to'], $filters, $entWhere, $entParams);
        } else {
            [$pFrom, $pTo] = Agg::comparisonRange($from, $to, $compare);
            $kpiCur = $kpi;
        }
        $kpiPrev = $this->kpi($pFrom, $pTo, $filters, $entWhere, $entParams);
        $total = $this->kpi($from, $to, $filters, '1 = 1', []);

        $delta = [];
        foreach (['einnahmen', 'kosten', 'ma_kosten', 'gewinn', 'marge', 'einsaetze_total', 'ist_stunden'] as $key) {
            $delta[$key] = Agg::delta((float) $kpiCur[$key], (float) $kpiPrev[$key]);
        }

        $breakdowns = [];
        foreach (self::SUBDIMS[$dim] as $sub) {
            $rows = $this->subBreakdown($sub, $from, $to, $filters, $entWhere, $entParams);
            if ($rows !== null) {
                $breakdowns[$sub] = $rows;
            }
        }

        return [
            'entity' => $entity,
            'period' => ['from' => $from, 'to' => $to, 'label' => Agg::periodLabel($from, $to)],
            'compare' => ['mode' => $compare, 'from' => $pFrom, 'to' => $pTo, 'cut' => (bool) ($cmp['cut'] ?? false),
                'label' => Agg::periodLabel($pFrom, $pTo) . (($cmp['cut'] ?? false) ? ' (bis zum gleichen Tag)' : '')],
            'filters' => $filters,
            'kpi' => $kpi,
            'kpi_prev' => $kpiPrev,
            'delta' => $delta,
            'share' => [
                'einnahmen_pct' => $total['einnahmen'] > 0 ? round($kpi['einnahmen'] / $total['einnahmen'] * 100, 1) : 0,
                'einsaetze_pct' => $total['einsaetze_total'] > 0 ? round($kpi['einsaetze_total'] / $total['einsaetze_total'] * 100, 1) : 0,
                'stunden_pct' => $total['ist_stunden'] > 0 ? round($kpi['ist_stunden'] / $total['ist_stunden'] * 100, 1) : 0,
                'gewinn_pct' => $total['gewinn'] != 0 ? round($kpi['gewinn'] / $total['gewinn'] * 100, 1) : 0,
                'total_einnahmen' => $total['einnahmen'],
                'total_gewinn' => $total['gewinn'],
            ],
            // Status of the shifts — the cockpit classes (Zeiterfassung):
            // bestaetigt = released for the customer, ausstehend = performed
            // but not (yet) released. Partly released shifts sit in both.
            'status' => [
                'bestaetigt' => $kpi['bestaetigt_count'],
                'ausstehend' => $kpi['ausstehend_count'],
                'nein' => $kpi['nicht_bestaetigt_count'],
                'unbesetzt' => $kpi['unbesetzt_count'],
                'geplant' => $kpi['geplant_count'],
            ],
            'bestellungen' => $this->bestellungen($dim, $id, $from, $to),
            'trend' => $this->trend($from, $to, $filters, $entWhere, $entParams, $granularity),
            'granularity' => $granularity,
            'breakdowns' => $breakdowns,
            'heatmap' => $this->heatmap($from, $to, $filters, $entWhere, $entParams),
            'einsaetze' => $this->einsaetze($from, $to, $filters, $entWhere, $entParams, $limit),
            'einsaetze_limit' => $limit,
        ];
    }

    /**
     * True when the requested drill-down entity belongs to the caller's
     * allowed Bereiche. Unrestricted users pass always.
     */
    private function entityInScope($user, string $dim, string $id): bool
    {
        if (BereichScope::for($user) === null) {
            return true;
        }
        return match ($dim) {
            'kunde'       => BereichScope::allowsKunde($user, $id),
            'objekt'      => BereichScope::allowsObjekt($user, $id),
            'nu'          => BereichScope::allowsNu($user, $id),
            'artikel'     => BereichScope::allowsArtikel($user, $id),
            'mitarbeiter' => BereichScope::allowsWorker($user, $id),
            'bereich'     => BereichScope::userAllows($user, $id),
            'schichtart'  => true,
            default       => false,
        };
    }

    // ============================================================
    // ENTITY (Stammdaten header)
    // ============================================================

    private function entity(string $dim, string $id): ?array
    {
        $db = DB::connection('tenant');
        $pick = function (object $row, array $map): array {
            $out = [];
            foreach ($map as $col => $label) {
                $v = $row->$col ?? null;
                if ($v !== null && trim((string) $v) !== '') {
                    $out[] = ['label' => $label, 'value' => (string) $v];
                }
            }

            return $out;
        };

        switch ($dim) {
            case 'kunde':
                $r = $db->selectOne('SELECT * FROM kunden WHERE kunde_id = ? LIMIT 1', [$id]);
                if (!$r) {
                    return null;
                }
                $addr = trim(implode(' ', array_filter([$r->strasse ?? '', trim(($r->plz ?? '').' '.($r->ort ?? ''))])));
                $r->__addr = $addr;
                $objCount = (int) ($db->selectOne('SELECT COUNT(*) c FROM objekte WHERE kunden_id = ? AND COALESCE(aktiv,1)=1', [$id])->c ?? 0);

                return ['dim' => $dim, 'id' => $id, 'name' => $r->kundenname, 'icon' => 'person',
                    'stammdaten' => array_merge($pick($r, [
                        'kunden_nr_intern' => 'Kunden-Nr. intern', 'kunden_nr_kunde' => 'Kunden-Nr. Kunde', 'bereich' => 'Bereich',
                        'abrechnungstyp' => 'Abrechnung', '__addr' => 'Adresse', 'haupt_ap' => 'Ansprechpartner',
                        'telefon1' => 'Telefon', 'mobil' => 'Mobil', 'email' => 'E-Mail',
                    ]), [['label' => 'Aktive Objekte', 'value' => (string) $objCount]]),
                    'aktiv' => (bool) ($r->aktiv ?? true)];

            case 'objekt':
                $r = $db->selectOne('SELECT o.*, k.kundenname FROM objekte o LEFT JOIN kunden k ON o.kunden_id = k.kunde_id WHERE o.obj_id = ? LIMIT 1', [$id]);
                if (!$r) {
                    return null;
                }
                $r->__addr = trim(implode(' ', array_filter([$r->strasse ?? '', trim(($r->plz ?? '').' '.($r->ort ?? ''))])));
                $artCount = (int) ($db->selectOne('SELECT COUNT(*) c FROM artikel WHERE obj_id = ? AND COALESCE(aktiv,1)=1', [$id])->c ?? 0);

                return ['dim' => $dim, 'id' => $id, 'name' => $r->objektname, 'icon' => 'location_on',
                    'parent' => ['dim' => 'kunde', 'id' => $r->kunden_id, 'name' => $r->kundenname],
                    'stammdaten' => array_merge($pick($r, [
                        'kundenname' => 'Kunde', 'obj_nr_intern' => 'Objekt-Nr. intern', 'obj_nr_kunde' => 'Objekt-Nr. Kunde',
                        'bereich' => 'Bereich', '__addr' => 'Adresse', 'ansprechpartner' => 'Ansprechpartner', 'telefon' => 'Telefon',
                    ]), [['label' => 'Aktive Artikel', 'value' => (string) $artCount]]),
                    'aktiv' => (bool) ($r->aktiv ?? true)];

            case 'nu':
                $r = $db->selectOne('SELECT * FROM nachunternehmer WHERE nu_id = ? LIMIT 1', [$id]);
                if (!$r) {
                    return null;
                }
                $r->__addr = trim(implode(' ', array_filter([$r->strasse ?? '', trim(($r->plz ?? '').' '.($r->ort ?? ''))])));

                return ['dim' => $dim, 'id' => $id, 'name' => $r->firmenname, 'icon' => 'engineering',
                    'stammdaten' => $pick($r, [
                        'bereich' => 'Bereich', 'ansprechpartner' => 'Ansprechpartner', '__addr' => 'Adresse',
                        'telefon1' => 'Telefon', 'mobil' => 'Mobil', 'email' => 'E-Mail', 'website' => 'Website',
                    ]),
                    'aktiv' => (bool) ($r->aktiv ?? true)];

            case 'artikel':
                $r = $db->selectOne('SELECT a.*, o.objektname, o.kunden_id, k.kundenname FROM artikel a LEFT JOIN objekte o ON a.obj_id = o.obj_id LEFT JOIN kunden k ON o.kunden_id = k.kunde_id WHERE a.artikel_id = ? LIMIT 1', [$id]);
                if (!$r) {
                    return null;
                }
                $r->__preis = number_format((float) $r->preis_kunde, 2, ',', '.').' €';
                $r->__nu = number_format((float) $r->nu_preis, 2, ',', '.').' €';
                $r->__spanne = number_format((float) $r->preis_kunde - (float) $r->nu_preis, 2, ',', '.').' €';

                return ['dim' => $dim, 'id' => $id, 'name' => $r->artikel_name, 'icon' => 'sell',
                    'parent' => ['dim' => 'objekt', 'id' => $r->obj_id, 'name' => $r->objektname],
                    'stammdaten' => $pick($r, [
                        'kundenname' => 'Kunde', 'objektname' => 'Objekt', 'bereich' => 'Bereich', 'modell' => 'Modell', 'einheit' => 'Einheit',
                        '__preis' => 'Preis Kunde', '__nu' => 'NU-Preis', '__spanne' => 'Spanne / Einheit',
                    ]),
                    'aktiv' => (bool) ($r->aktiv ?? true)];

            case 'bereich':
                return ['dim' => $dim, 'id' => $id, 'name' => $id, 'icon' => 'layers', 'stammdaten' => [], 'aktiv' => true];

            case 'schichtart':
                $labels = ['tagdienst' => 'Tagdienst (Mo–Sa)', 'nachtdienst' => 'Nachtdienst', 'sonntag' => 'Sonntag',
                    'feiertag' => 'Feiertag', 'nacht_sonntag' => 'Nacht + Sonntag', 'nacht_feiertag' => 'Nacht + Feiertag'];

                return ['dim' => $dim, 'id' => $id, 'name' => $labels[$id] ?? ucfirst($id), 'icon' => 'dark_mode', 'stammdaten' => [], 'aktiv' => true];

            case 'mitarbeiter':
                $r = $db->selectOne("SELECT m.*, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.vorname,''),' ',COALESCE(m.name,''))),''), m.dienst_nr) AS full_name FROM mitarbeiter m WHERE m.dienst_nr = ? LIMIT 1", [$id]);
                if (!$r) {
                    return null;
                }
                if (isset($r->stundenlohn) && $r->stundenlohn !== null) {
                    // 'gehalt' stores a monthly salary in stundenlohn — show
                    // it as €/Monat instead of a fake €/h figure.
                    $r->__lohn = ($r->lohn_typ ?? null) === 'gehalt'
                        ? number_format((float) $r->stundenlohn, 2, ',', '.').' €/Monat'
                        : number_format((float) $r->stundenlohn, 2, ',', '.').' €/h';
                }

                return ['dim' => $dim, 'id' => $id, 'name' => $r->full_name, 'icon' => 'badge',
                    'stammdaten' => $pick($r, ['dienst_nr' => 'Dienst-Nr.', '__lohn' => ($r->lohn_typ ?? null) === 'gehalt' ? 'Gehalt' : 'Stundenlohn', 'telefon' => 'Telefon', 'mobil' => 'Mobil', 'email' => 'E-Mail']),
                    'aktiv' => (bool) ($r->aktiv ?? true)];
        }

        return null;
    }

    /** SQL predicate (on alias `e`) that restricts to the entity. */
    private function entityCondition(string $dim, string $id): array
    {
        return match ($dim) {
            'kunde' => ['e.kunden_id = ?', [$id]],
            'objekt' => ['e.obj_id = ?', [$id]],
            'nu' => ['e.nu_id = ?', [$id]],
            'artikel' => ['e.artikel_id = ?', [$id]],
            // Effective Bereich (artikel → kunde) — same key as the cockpit's Bereich rows.
            'bereich' => ['COALESCE(' . BereichScope::derivedExprSub('e') . ", '–') = ?", [$id]],
            'schichtart' => ["COALESCE(NULLIF(e.schichtart, ''), 'tagdienst') = ?", [$id]],
            'mitarbeiter' => ['e.einsatz_id IN (SELECT emx.einsatz_id FROM einsatz_mitarbeiter emx WHERE emx.dienst_nr = ?)', [$id]],
        };
    }

    // ============================================================
    // METRICS
    // ============================================================

    /** Request-scoped cockpit context (settings, Vollkostenfaktor, Ø wage) and basis classes. */
    private array $ctx = [];
    private array $statuses = ['freigegeben', 'offen'];

    /**
     * KPI bag for the entity — the SAME calculation as the cockpit (hours
     * from the Zeiterfassung, DB II after Lohnnebenkosten). Legacy keys are
     * kept for the panel: ma_kosten = Personal-Vollkosten, gewinn = DB II.
     */
    private function kpi(string $from, string $to, array $filters, string $entWhere, array $entParams): array
    {
        $extra = "AND {$entWhere}";
        $t = Cockpit::totals($this->ctx, $from, $to, $filters, $extra, $entParams);
        $st = Cockpit::result($this->ctx, $t, $this->statuses);
        $all = Cockpit::result($this->ctx, $t, ['freigegeben', 'offen']);
        $rest = array_values(array_diff(['freigegeben', 'offen'], $this->statuses));
        $off = $rest ? Cockpit::result($this->ctx, $t, $rest) : Cockpit::stufen([], $this->ctx['F'], $this->ctx['s']);

        [$where, $params] = Agg::whereClause($from, $to, $filters);
        $p = array_merge($params, $entParams);
        $w = Cockpit::weightSql($this->statuses);
        $sur = Agg::surchargeExpr(Agg::billableStdExpr());
        $r = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS einsaetze_total,
                   COALESCE(SUM(({$sur}) * {$w}), 0) AS aufschlag,
                   COUNT(DISTINCT e.kunden_id) AS kunden_aktiv, COUNT(DISTINCT e.obj_id) AS objekte_aktiv,
                   COUNT(DISTINCT e.nu_id) AS nu_aktiv, COUNT(DISTINCT e.artikel_id) AS artikel_aktiv,
                   COUNT(DISTINCT e.datum) AS arbeitstage, MIN(e.datum) AS erster, MAX(e.datum) AS letzter
              " . Cockpit::from() . "
             WHERE {$where} AND {$entWhere}
        ", $p);
        $ma = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT em.dienst_nr) AS c
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE {$where} AND {$entWhere}
        ", $p);

        $leg = Cockpit::legacyRow($st);
        $n = max(1, $st['einsaetze']);
        $h = $st['stunden'];

        return $leg + [
            'aufschlag'          => round((float) $r->aufschlag, 2),
            'personal'           => $st['personal'],
            'rohertrag'          => $st['rohertrag'],
            'marge_db1'          => $st['marge_db1'],
            'ma_kosten_offen'    => round($off['personal'] + $off['lohnnebenkosten'], 2),
            'einnahmen_offen'    => $off['umsatz'],
            'kosten_offen'       => $off['nu_kosten'],
            'einnahmen_erwartet' => $all['umsatz'],
            'gewinn_erwartet'    => $all['db2'],
            'gewinn_pro_einsatz'    => $st['einsaetze'] ? round($st['db2'] / $n, 2) : 0,
            'einnahmen_pro_einsatz' => $st['einsaetze'] ? round($st['umsatz'] / $n, 2) : 0,
            'einnahmen_pro_stunde'  => $h > 0 ? round($st['umsatz'] / $h, 2) : 0,
            'gewinn_pro_stunde'     => $h > 0 ? round($st['db2'] / $h, 2) : 0,
            'einsaetze_total'        => (int) $r->einsaetze_total,
            'einsaetze_basis'        => $st['einsaetze'],
            'bestaetigt_count'       => $t['freigegeben']['einsaetze'],
            'ausstehend_count'       => $t['offen']['einsaetze'],
            'nicht_bestaetigt_count' => $t['abgelehnt']['einsaetze'],
            'unbesetzt_count'        => $t['unbesetzt']['einsaetze'],
            'geplant_count'          => $t['geplant']['einsaetze'],
            'ist_stunden_offen'      => $off['stunden'],
            'stunden_pro_einsatz'    => $st['einsaetze'] ? round($h / $n, 2) : 0,
            'kunden_aktiv'           => (int) $r->kunden_aktiv,
            'objekte_aktiv'          => (int) $r->objekte_aktiv,
            'nu_aktiv'               => (int) $r->nu_aktiv,
            'artikel_aktiv'          => (int) $r->artikel_aktiv,
            'mitarbeiter_aktiv'      => (int) ($ma->c ?? 0),
            'arbeitstage'            => (int) $r->arbeitstage,
            'erster_einsatz'         => $r->erster,
            'letzter_einsatz'        => $r->letzter,
        ];
    }

    private function trend(string $from, string $to, array $filters, string $entWhere, array $entParams, string $granularity): array
    {
        $rows = Cockpit::rows($this->ctx, Agg::bucketExpression($granularity) . ' AS bucket', 'bucket', '',
            $from, $to, $filters, $this->statuses, "AND {$entWhere}", $entParams, ['bucket']);
        $points = array_map(fn ($r) => ['bucket' => $r['bucket']] + Cockpit::legacyRow($r), $rows);
        usort($points, fn ($a, $b) => strcmp((string) $a['bucket'], (string) $b['bucket']));

        return Agg::fillMissingBuckets($points, $from, $to, $granularity);
    }

    /**
     * Generic secondary breakdown inside the entity. Returns null when the
     * dimension isn't available on this tenant (e.g. schichtart column missing).
     */
    private function subBreakdown(string $sub, string $from, string $to, array $filters, string $entWhere, array $entParams): ?array
    {
        $extra = "AND {$entWhere}";

        if ($sub === 'mitarbeiter') {
            $rows = Cockpit::mitarbeiterRows($this->ctx, $from, $to, $filters, $this->statuses, $extra, $entParams);
            $out = array_map(fn ($r) => ['dim_id' => $r['dim_id'], 'dim_value' => $r['name']] + Cockpit::legacyRow($r), $rows);
            usort($out, fn ($a, $b) => $b['ist_stunden'] <=> $a['ist_stunden']);

            return array_slice($out, 0, 15);
        }

        if ($sub === 'schichtart' && !Schema::connection('tenant')->hasColumn('einsaetze', 'schichtart')) {
            return null;
        }

        $bExpr = 'COALESCE(' . BereichScope::derivedExprSub('e') . ", '–')";
        [$idExpr, $nameExpr, $join, $drillDim] = match ($sub) {
            'kunden' => ['e.kunden_id', "COALESCE(MAX(k.kundenname), '–')", 'LEFT JOIN kunden k ON e.kunden_id = k.kunde_id', 'kunde'],
            'objekte' => ['e.obj_id', "COALESCE(MAX(o.objektname), '–')", 'LEFT JOIN objekte o ON e.obj_id = o.obj_id', 'objekt'],
            'nu' => ["COALESCE(e.nu_id, '')", "COALESCE(MAX(n.firmenname), 'Intern (eigene Mitarbeiter)')", 'LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id', 'nu'],
            'artikel' => ['e.artikel_id', "COALESCE(MAX(a.artikel_name), '–')", '', 'artikel'],
            'schichtart' => ["COALESCE(NULLIF(e.schichtart, ''), 'tagdienst')", "MAX(COALESCE(NULLIF(e.schichtart, ''), 'tagdienst'))", '', 'schichtart'],
            'bereich' => [$bExpr, "MAX({$bExpr})", '', 'bereich'],
        };

        $rows = Cockpit::rows($this->ctx, "{$idExpr} AS dim_id, {$nameExpr} AS dim_value", 'dim_id', $join,
            $from, $to, $filters, $this->statuses, $extra, $entParams, ['dim_id', 'dim_value']);

        $labels = ['tagdienst' => 'Tagdienst (Mo–Sa)', 'nachtdienst' => 'Nachtdienst', 'sonntag' => 'Sonntag',
            'feiertag' => 'Feiertag', 'nacht_sonntag' => 'Nacht + Sonntag', 'nacht_feiertag' => 'Nacht + Feiertag'];
        $out = array_map(function ($r) use ($sub, $drillDim, $labels) {
            $row = ['dim_id' => $r['dim_id'], 'dim_value' => $r['dim_value']] + Cockpit::legacyRow($r);
            $row['drill_dim'] = ($sub === 'nu' && $row['dim_id'] === '') ? null : $drillDim;
            if ($sub === 'schichtart') {
                $row['dim_value'] = $labels[$row['dim_id']] ?? ucfirst((string) $row['dim_id']);
            }
            return $row;
        }, $rows);
        usort($out, fn ($a, $b) => $b['einnahmen'] <=> $a['einnahmen']);

        return array_slice($out, 0, 15);
    }

    private function heatmap(string $from, string $to, array $filters, string $entWhere, array $entParams): array
    {
        $rows = Cockpit::rows($this->ctx, 'MOD(DAYOFWEEK(e.datum) + 5, 7) AS dow, MONTH(e.datum) AS mon', 'dow, mon', '',
            $from, $to, $filters, $this->statuses, "AND {$entWhere}", $entParams, ['dow', 'mon']);

        $grid = [];
        foreach ($rows as $r) {
            $grid[(int) $r['dow']][(int) $r['mon']] = ['einsaetze' => $r['einsaetze'], 'einnahmen' => $r['umsatz']];
        }
        $years = range((int) date('Y', strtotime($from)), (int) date('Y', strtotime($to)));

        return ['grid' => $grid, 'years' => $years, 'aggregated_years' => count($years) > 1, 'period' => ['from' => $from, 'to' => $to]];
    }

    private function einsaetze(string $from, string $to, array $filters, string $entWhere, array $entParams, int $limit): array
    {
        [$where, $params] = Agg::whereClause($from, $to, $filters);
        $workers = Agg::workerCountExpr();
        $hasSchicht = Schema::connection('tenant')->hasColumn('einsaetze', 'schichtart');
        $schichtCol = $hasSchicht ? "COALESCE(NULLIF(e.schichtart, ''), 'tagdienst')" : "'tagdienst'";

        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.bestell_id, e.datum, e.ist_start, e.ist_ende, e.pause_minuten, e.ist_std_menge,
                   COALESCE(e.zuschlag_pct, 0) AS zuschlag_pct, {$schichtCol} AS schichtart,
                   e.kunden_id, k.kundenname, e.obj_id, o.objektname, e.artikel_id, a.artikel_name, e.nu_id, n.firmenname AS nu_name,
                   {$workers} AS workers,
                   (SELECT GROUP_CONCAT(COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m2.vorname,''),' ',COALESCE(m2.name,''))),''), em2.dienst_nr) ORDER BY em2.sortierung SEPARATOR ', ')
                      FROM einsatz_mitarbeiter em2 LEFT JOIN mitarbeiter m2 ON em2.dienst_nr = m2.dienst_nr
                     WHERE em2.einsatz_id = e.einsatz_id) AS mitarbeiter_namen,
                   " . Cockpit::einsatzCols($this->ctx, $this->statuses) . "
              " . Cockpit::from() . "
              LEFT JOIN kunden k          ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte o         ON e.obj_id     = o.obj_id
              LEFT JOIN nachunternehmer n ON e.nu_id      = n.nu_id
             WHERE {$where} AND {$entWhere}
          ORDER BY e.datum DESC, e.ist_start DESC, e.id DESC
             LIMIT {$limit}
        ", array_merge($params, $entParams));

        return array_map(fn ($r) => [
            'einsatz_id' => $r->einsatz_id, 'bestell_id' => $r->bestell_id, 'datum' => $r->datum,
            'ist_start' => $r->ist_start, 'ist_ende' => $r->ist_ende, 'pause_minuten' => $r->pause_minuten !== null ? (int) $r->pause_minuten : null,
            'schichtart' => $r->schichtart, 'zuschlag_pct' => (float) $r->zuschlag_pct,
            'kunden_id' => $r->kunden_id, 'kundenname' => $r->kundenname, 'obj_id' => $r->obj_id, 'objektname' => $r->objektname,
            'artikel_id' => $r->artikel_id, 'artikel_name' => $r->artikel_name, 'nu_id' => $r->nu_id, 'nu_name' => $r->nu_name,
            'workers' => (int) $r->workers, 'mitarbeiter_namen' => $r->mitarbeiter_namen,
            'ist_std_menge' => $r->ist_std_menge !== null ? (float) $r->ist_std_menge : null, 'billable_std' => round((float) $r->billable_std, 2),
            'einnahmen' => round((float) $r->einnahmen, 2), 'nu_kosten' => round((float) $r->nu_kosten, 2),
            'ma_kosten' => round((float) $r->ma_kosten, 2), 'gewinn' => round((float) $r->gewinn, 2),
            'hms_bestaetigt' => Cockpit::einsatzStatus($r->frei_anteil, $r->rest_status),
        ], $rows);
    }

    private function bestellungen(string $dim, string $id, string $from, string $to): ?array
    {
        $filter = match ($dim) {
            'kunde' => 'kunden_id',
            'objekt' => 'obj_id',
            'nu' => 'nu_id',
            default => null,
        };
        if (!$filter) {
            return null;
        }
        $r = Agg::plannedOrderKpi($from, $to, [$filter => $id]);

        return ['total' => (int) $r->bestellungen_total, 'soll_stunden' => round((float) $r->soll_stunden, 2)];
    }
}
    