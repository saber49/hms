<?php

namespace App\Services;

use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abwesenheiten in der Zeiterfassung — pulls the approved/pending records
 * of "Anträge & Urlaub" (mitarbeiter_abwesenheiten) into the Monatsabschluss
 * and converts every absence day into PAID hours where German law (or the
 * company's own bezahlt flag) requires it.
 *
 * Rules implemented:
 *   - bezahlt = 0                              → 0 h (unbezahlter Urlaub, Wunschfrei, …)
 *   - record `status` = 'pending'              → computed but flagged "beantragt"
 *   - day with a planned shift                 → credit = planned shift hours
 *     (Entgeltfortzahlung: what the worker WOULD have earned — §3/§4 EFZG
 *      Lohnausfallprinzip; same idea drives BUrlG §11 for Urlaub)
 *   - day without a shift                      → credit = 13-week average
 *     (§11 BUrlG: average pay of the last 13 weeks / 91 days, computed on
 *      recorded Ist hours, absence days excluded from the denominator)
 *   - no history (< 14 counted days)           → contract daily hours
 *     (arbeitsstunden_typ 'wöchentlich' → h/7, 'monatlich' → h*12/365 —
 *      per calendar day, matching `tage` which counts every calendar day)
 *   - `typ` = 'Feiertag' without a shift       → credit = 13-wk avg/contract
 *     (§2 EFZG pay only when work would otherwise have been due)
 *   - kategorie 'krank' before eintritt+28d    → 0 h (§3 EFZG Wartezeit)
 *   - continuous krank episode > 42 cal. days  → 0 h (§3 EFZG 6-Wochen-Grenze)
 *   - day after `austritt`                     → 0 h
 *   - day with recorded work                   → 0 h ("gearbeitet" hint)
 *
 * `covered` maps dienst_nr+datum → absence info for every FULL-day approved
 * absence: Monatsabschluss marks those confirmed shifts so a sick worker's
 * plan hours are not counted as worked — they become absence credit instead.
 */
class AbsenceCreditService
{
    /** mitarbeiter.bundesland (full name) → region suffix of feiertage.region ('DE-XX'). */
    private const BUNDESLAND_REGION = [
        'Baden-Württemberg' => 'BW', 'Bayern' => 'BY', 'Berlin' => 'BE',
        'Brandenburg' => 'BB', 'Bremen' => 'HB', 'Hamburg' => 'HH',
        'Hessen' => 'HE', 'Mecklenburg-Vorpommern' => 'MV',
        'Niedersachsen' => 'NI', 'Nordrhein-Westfalen' => 'NW',
        'Rheinland-Pfalz' => 'RP', 'Saarland' => 'SL', 'Sachsen' => 'SN',
        'Sachsen-Anhalt' => 'ST', 'Schleswig-Holstein' => 'SH',
        'Thüringen' => 'TH',
    ];

    private const EFZG_DAYS = 42;      // §3 EFZG: 6 Wochen je Erkrankung
    private const WARTEZEIT = 28;      // §3 EFZG: erst nach 4 Wochen Beschäftigung
    private const REF_TAGE = 91;       // §11 BUrlG: 13 Wochen Referenzzeitraum
    private const REF_MIN_TAGE = 14;   // shorter history → Vertragsfallback

    private const KRANK_TYPEN = ['Krank Mit AU', 'Krank Ohne AU', 'Krankmeldung'];

    private const BASIS_LABEL = [
        'plan'      => 'Schichtplan',
        'schnitt13' => '13-Wochen-Schnitt',
        'vertrag'   => 'Vertragsarbeitszeit',
        'feiertag'  => 'Feiertag',
        'gemischt'  => 'Gemischt',
        'unbezahlt' => 'Unbezahlt',
        'gearbeitet'=> 'Gearbeitet',
        'wartezeit' => 'Wartezeit (§3 EFZG)',
        'efzg'      => '6-Wochen-Grenze',
        'austritt'  => 'Nach Austritt',
        'keine'     => 'Keine Grundlage',
    ];

    /**
     * @return array{records: array, summen: array, covered: array, total_std: float}
     */
    public static function forPeriod(object $user, string $von, string $bis): array
    {
        $empty = ['records' => new \stdClass(), 'summen' => new \stdClass(), 'covered' => [], 'total_std' => 0.0];
        if (!Schema::connection('tenant')->hasTable('mitarbeiter_abwesenheiten')) {
            return $empty;
        }

        // Look back far enough for the 13-week reference and EFZG episodes
        // that started before the requested period.
        $lookback = Carbon::parse($von)->subDays(self::REF_TAGE)->toDateString();

        [$wSql, $wP] = BereichScope::workerPredicate($user, 'mi');
        $abwRows = DB::connection('tenant')->select("
            SELECT a.*, mi.vorname, mi.name, mi.foto, mi.bereich, mi.aktiv AS mi_aktiv,
                   mi.eintritt, mi.austritt, mi.arbeitsstunden, mi.arbeitsstunden_typ, mi.bundesland
            FROM mitarbeiter_abwesenheiten a
            JOIN mitarbeiter mi ON mi.dienst_nr = a.dienst_nr
            WHERE COALESCE(a.status, 'genehmigt') != 'abgelehnt'
              AND a.startdatum <= ? AND COALESCE(a.enddatum, a.startdatum) >= ?
              AND $wSql
            ORDER BY a.dienst_nr, a.startdatum, a.id
        ", array_merge([$bis, $lookback], $wP));
        if (!$abwRows) return $empty;

        $dienstNrs = array_values(array_unique(array_map(fn ($r) => (string) $r->dienst_nr, $abwRows)));

        // Recorded Ist + planned hours per worker per day, across the whole
        // window (reference period + requested period).
        $storno = Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')
            ? ' AND e.bestell_storno_at IS NULL' : '';
        $ph = implode(',', array_fill(0, count($dienstNrs), '?'));
        $shiftRows = DB::connection('tenant')->select("
            SELECT em.dienst_nr, e.datum,
                   SUM(CASE WHEN em.ist_std IS NOT NULL AND em.ist_std > 0 THEN em.ist_std ELSE 0 END) AS ist,
                   SUM(CASE WHEN em.ist_std IS NOT NULL AND em.ist_std > 0 THEN 0
                            ELSE COALESCE(NULLIF(e.ist_std_menge, 0), 0) END) AS plan,
                   MAX(e.ist_start) AS s_von, MAX(e.ist_ende) AS s_bis,
                   MAX(COALESCE(e.pause_minuten, 0)) AS s_pause
            FROM einsatz_mitarbeiter em
            JOIN einsaetze e ON e.einsatz_id = em.einsatz_id
            WHERE e.datum BETWEEN ? AND ? {$storno}
              AND em.dienst_nr IN ({$ph})
            GROUP BY em.dienst_nr, e.datum
        ", array_merge([$lookback, $bis], $dienstNrs));

        // dayMap[dienst_nr][datum] = ['ist' => h, 'plan' => h]
        $dayMap = [];
        foreach ($shiftRows as $r) {
            $plan = (float) $r->plan;
            if ($plan <= 0 && (float) $r->ist <= 0 && $r->s_von && $r->s_bis) {
                $span = self::spanMinutes($r->s_von, $r->s_bis);
                if ($span > 0) $plan = max(0, $span - (int) $r->s_pause) / 60;
            }
            $d = substr((string) $r->datum, 0, 10);
            $dayMap[$r->dienst_nr][$d] = ['ist' => (float) $r->ist, 'plan' => $plan];
        }

        // Feiertage per region within the window.
        $holidayRegionDays = []; // region => [datum => true]
        if (Schema::connection('tenant')->hasTable('feiertage')) {
            foreach (DB::connection('tenant')->table('feiertage')
                ->where('aktiv', 1)->whereBetween('datum', [$lookback, $bis])
                ->get(['datum', 'region']) as $h) {
                $d = is_object($h->datum) && method_exists($h->datum, 'format')
                    ? $h->datum->format('Y-m-d') : substr((string) $h->datum, 0, 10);
                $holidayRegionDays[$h->region][$d] = true;
            }
        }

        $byWorker = [];
        foreach ($abwRows as $r) $byWorker[$r->dienst_nr][] = $r;

        $records = [];
        $summen = [];
        $covered = [];
        $totalStd = 0.0;

        foreach ($byWorker as $dienstNr => $rows) {
            $w = $rows[0];
            $eintritt = $w->eintritt ? substr((string) $w->eintritt, 0, 10) : null;
            $austritt = $w->austritt ? substr((string) $w->austritt, 0, 10) : null;
            $contractDay = self::contractDayHours($w->arbeitsstunden_typ ?? null, $w->arbeitsstunden ?? null);
            $days = $dayMap[$dienstNr] ?? [];

            // Region holidays of this worker ('DE' + his Bundesland).
            $holidays = $holidayRegionDays['DE'] ?? [];
            $state = self::BUNDESLAND_REGION[trim((string) ($w->bundesland ?? ''))] ?? null;
            if ($state) $holidays += ($holidayRegionDays['DE-' . $state] ?? []);

            // All calendar days covered by this worker's own absences — the
            // denominator of the 13-week average must skip them.
            $absDays = [];
            foreach ($rows as $r) {
                foreach (self::eachDay($r->startdatum, $r->enddatum ?: $r->startdatum) as $d) {
                    $absDays[$d] = true;
                }
            }

            // EFZG episodes: continuous runs of 'krank' records (gap ≤ 1 day).
            $episodes = self::krankEpisodes($rows);

            $sum = ['std' => 0.0, 'tage' => 0.0, 'krank_tage' => 0.0,
                    'urlaub_tage' => 0.0, 'sonstiges_tage' => 0.0,
                    'beantragt' => 0, 'unbezahlt_tage' => 0.0];

            foreach ($rows as $r) {
                $kat = self::kategorie($r);
                $ganz = (int) ($r->ganzer_tag ?? 1) === 1;
                $status = ($r->status ?? null) ?: 'genehmigt';
                $isKrank = $kat === 'krank';
                $avg = self::avg13($days, $absDays, $eintritt, (string) $r->startdatum);

                $rec = [
                    'id' => (int) $r->id,
                    'typ' => $r->typ,
                    'kategorie' => $kat,
                    'status' => $status,
                    'zeit_freigabe' => (int) ($r->zeit_freigabe ?? 0),
                    'bezahlt' => (int) $r->bezahlt,
                    'ganzer_tag' => (int) ($r->ganzer_tag ?? 1),
                    'startdatum' => substr((string) $r->startdatum, 0, 10),
                    'enddatum' => substr((string) ($r->enddatum ?: $r->startdatum), 0, 10),
                    'tage' => (float) $r->tage,
                    'beschreibung' => $r->beschreibung,
                    'vorname' => $r->vorname, 'name' => $r->name,
                    'foto' => $r->foto, 'bereich' => $r->bereich,
                    'mi_aktiv' => (int) ($r->mi_aktiv ?? 1),
                    'zeitraum_tage' => 0.0,
                    'std' => 0.0,
                    'basis' => null,
                    'basis_label' => null,
                    'hinweise' => [],
                    'days' => [],
                ];

                $basisCounts = [];
                $ep = $isKrank ? self::episodeFor($episodes, (string) $r->startdatum) : null;

                foreach (self::eachDay($r->startdatum, $r->enddatum ?: $r->startdatum) as $d) {
                    $frac = $ganz ? 1.0 : 0.5;
                    [$basis, $kredit] = self::dayCredit(
                        $d, $ganz, $r, $isKrank, $days, $holidays,
                        $eintritt, $austritt, $contractDay, $avg, $ep
                    );
                    if ($d < $von || $d > $bis) continue;

                    $rec['zeitraum_tage'] += $frac;
                    $rec['std'] += $kredit;
                    $basisCounts[$basis] = ($basisCounts[$basis] ?? 0) + 1;
                    $rec['days'][] = ['d' => $d, 'h' => round($kredit, 2), 'b' => $basis];

                    switch ($basis) {
                        case 'wartezeit':
                            $rec['hinweise']['w'] = 'Wartezeit: Entgeltfortzahlung erst ab 4 Wochen Beschäftigung (§3 EFZG)'; break;
                        case 'efzg':
                            $rec['hinweise']['e'] = '6-Wochen-Grenze der Entgeltfortzahlung erreicht'; break;
                        case 'gearbeitet':
                            $rec['hinweise']['g'] = 'An diesem Tag wurde gearbeitet'; break;
                        case 'austritt':
                            $rec['hinweise']['a'] = 'Nach dem Austritt'; break;
                        case 'keine':
                            $rec['hinweise']['k'] = 'Keine Berechnungsgrundlage (kein Verlauf, keine Vertragsstunden)'; break;
                    }
                }

                if (!$r->bezahlt) $rec['hinweise']['u'] = 'Unbezahlte Abwesenheit';
                if ($status === 'pending') { $rec['hinweise']['p'] = 'Beantragt — noch nicht entschieden'; $sum['beantragt']++; }

                arsort($basisCounts);
                $dom = array_key_first($basisCounts);
                $rec['basis'] = count($basisCounts) > 1 ? 'gemischt' : ($dom ?: 'keine');
                $rec['basis_label'] = self::BASIS_LABEL[$rec['basis']] ?? $dom;
                $rec['hinweise'] = array_values($rec['hinweise']);
                $rec['std'] = round($rec['std'], 2);

                // Full-day approved absences mark the confirmed shift rows.
                if ($ganz && $status !== 'pending') {
                    foreach (self::eachDay($r->startdatum, $r->enddatum ?: $r->startdatum) as $d) {
                        if ($d >= $von && $d <= $bis) {
                            $covered[$dienstNr][$d] = ['typ' => $r->typ, 'kategorie' => $kat];
                        }
                    }
                }

                // Only records overlapping the period are sent.
                if ($rec['zeitraum_tage'] > 0) {
                    $records[$dienstNr][] = $rec;
                    $sum['std'] += $rec['std'];
                    $sum['tage'] += $rec['zeitraum_tage'];
                    $key = $kat === 'urlaub' ? 'urlaub_tage' : ($kat === 'krank' ? 'krank_tage' : 'sonstiges_tage');
                    $sum[$key] += $rec['zeitraum_tage'];
                    if (!$r->bezahlt) $sum['unbezahlt_tage'] += $rec['zeitraum_tage'];
                }
            }

            $sum['std'] = round($sum['std'], 2);
            $totalStd += $sum['std'];
            $summen[$dienstNr] = $sum;
        }

        return [
            'records' => (object) $records,
            'summen' => (object) $summen,
            'covered' => $covered,
            'total_std' => round($totalStd, 2),
        ];
    }

    /** Credit (paid hours) + basis key for ONE absence day. */
    private static function dayCredit(
        string $d, bool $ganz, object $r, bool $isKrank,
        array $days, array $holidays, ?string $eintritt, ?string $austritt,
        ?float $contractDay, ?float $avg, ?array $ep
    ): array {
        if ($austritt && $d > $austritt) return ['austritt', 0.0];

        $info = $days[$d] ?? null;
        $worked = $info && $info['ist'] > 0;
        // A recorded shift beats the absence — except a partial-day absence
        // still earns its complement next to the worked part.
        if ($worked && $ganz) return ['gearbeitet', 0.0];

        if (!(int) $r->bezahlt) return ['unbezahlt', 0.0];

        if ($isKrank && $eintritt && $d < Carbon::parse($eintritt)->addDays(self::WARTEZEIT)->toDateString()) {
            return ['wartezeit', 0.0];
        }
        if ($isKrank && $ep) {
            $idx = Carbon::parse($d)->diffInDays(Carbon::parse($ep['start'])) + 1;
            if ($idx > self::EFZG_DAYS) return ['efzg', 0.0];
        }

        $ref = $avg ?? $contractDay;
        if ($info && $info['plan'] > 0) {
            $basis = 'plan';
            $kredit = $info['plan'];
        } elseif (isset($holidays[$d])) {
            $basis = 'feiertag';
            $kredit = $ref ?? 0.0;
            if ($kredit <= 0) $basis = 'keine';
        } elseif ($ref !== null && $ref > 0) {
            $basis = $avg !== null ? 'schnitt13' : 'vertrag';
            $kredit = $ref;
        } else {
            $basis = 'keine';
            $kredit = 0.0;
        }

        if (!$ganz && $kredit > 0) {
            $span = ($r->startzeit && $r->endzeit) ? self::spanMinutes($r->startzeit, $r->endzeit) / 60 : null;
            $kredit = $span !== null ? min($span, $kredit) : $kredit / 2;
        }
        return [$basis, $kredit];
    }

    /** 13-week average daily hours (§11 BUrlG), null when history too thin. */
    private static function avg13(array $days, array $absDays, ?string $eintritt, string $refEnd): ?float
    {
        $start = Carbon::parse($refEnd)->subDays(self::REF_TAGE)->toDateString();
        if ($eintritt && $eintritt > $start) $start = $eintritt;
        if ($start >= $refEnd) return null;

        $worked = 0.0;
        foreach ($days as $d => $info) {
            if ($d >= $start && $d < $refEnd) $worked += $info['ist'];
        }
        $windowDays = Carbon::parse($start)->diffInDays(Carbon::parse($refEnd));
        $absInWindow = 0;
        foreach ($absDays as $d => $_) {
            if ($d >= $start && $d < $refEnd) $absInWindow++;
        }
        $denom = $windowDays - $absInWindow;
        if ($denom < self::REF_MIN_TAGE || $worked <= 0) return null;
        return $worked / $denom;
    }

    /** Continuous episodes of krank rows (gap ≤ 1 day merges them). */
    private static function krankEpisodes(array $rows): array
    {
        $eps = [];
        foreach ($rows as $r) {
            if (self::kategorie($r) !== 'krank') continue;
            $s = (string) $r->startdatum;
            $e = (string) ($r->enddatum ?: $r->startdatum);
            $n = count($eps);
            if ($n && $s <= Carbon::parse($eps[$n - 1]['end'])->addDay()->toDateString()) {
                if ($e > $eps[$n - 1]['end']) $eps[$n - 1]['end'] = $e;
            } else {
                $eps[] = ['start' => $s, 'end' => $e];
            }
        }
        return $eps;
    }

    private static function episodeFor(array $episodes, string $startdatum): ?array
    {
        foreach ($episodes as $ep) {
            if ($ep['start'] <= $startdatum && $ep['end'] >= $startdatum) return $ep;
        }
        return null;
    }

    /** Contract daily hours, per calendar day. */
    private static function contractDayHours(?string $typ, $stunden): ?float
    {
        $h = (float) ($stunden ?? 0);
        if ($h <= 0) return null;
        $t = mb_strtolower(trim((string) $typ));
        if (in_array($t, ['wöchentlich', 'woechentlich', 'woche'], true)) return $h / 7;
        if (in_array($t, ['monatlich', 'monat'], true)) return $h * 12 / 365;
        return null;
    }

    private static function kategorie(object $r): string
    {
        $kat = strtolower((string) ($r->kategorie ?? ''));
        if (in_array($kat, ['urlaub', 'krank', 'sonstiges'], true)) return $kat;
        if ($r->typ === 'Urlaub') return 'urlaub';
        if (in_array($r->typ, self::KRANK_TYPEN, true)) return 'krank';
        return 'sonstiges';
    }

    /** Inclusive ISO-day iterator. */
    private static function eachDay($start, $end): iterable
    {
        $cur = Carbon::parse(substr((string) $start, 0, 10));
        $last = Carbon::parse(substr((string) $end, 0, 10));
        while ($cur->lte($last)) {
            yield $cur->toDateString();
            $cur->addDay();
        }
    }

    /** Minutes between 'HH:MM' and 'HH:MM'; overnight adds a day. */
    private static function spanMinutes(string $von, string $bis): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr($von, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($bis, 0, 5)));
        $span = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($span <= 0) $span += 1440;
        return $span;
    }
}
