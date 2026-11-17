<?php

namespace App\Services;

use App\Http\Controllers\Api\TaskController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Schichtbörse — shared rules for the admin side (Dispo) and the worker app.
 *
 * A worker only ever sees / may take a shift he is suitable for (user
 * decision "nur passende Mitarbeiter"):
 *   - active, not already on the shift
 *   - Bereich: worker tagged with the shift's effective Bereich
 *     (artikel.bereich → kunden.bereich); untagged workers = shared pool
 *   - gender, when the Anforderungen ask for one gender only
 *   - qualifications from the Anforderungen: §34a by level (higher counts),
 *     other catalog qualifications by name; free text outside the catalog
 *     cannot be checked and does not block
 *   - no stadium ban (stadion_gesperrt) on a stadium shift (isStadiumShift)
 *   - no overlapping shift and no absence (same checks as the Dispo planner)
 */
class SchichtboerseService
{
    public static function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('schicht_angebote')
            && Schema::connection('tenant')->hasTable('schicht_anfragen');
    }

    private static function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    /** Shift + everything the exchange needs (names, Soll, Anforderungen, Bereich). */
    public static function einsatz(string $einsatzId): ?object
    {
        $hasDayAnf = Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen');
        $q = self::t('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'e.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('bestellungen as b', 'e.bestell_id', '=', 'b.bestell_id')
            ->leftJoin('bestelleingang_tage as d', function ($join) {
                $join->on('d.eingang_id', '=', 'b.eingang_id')->on('d.datum', '=', 'e.datum')
                    ->whereRaw('((e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr) OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2 WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum)))');
            })
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.einsatz_id', 'e.bestell_id', 'e.datum', 'e.ist_start', 'e.ist_ende', 'e.pause_minuten', 'e.ort',
                'e.beschreibung', 'e.hms_bestaetigt', 'e.bestell_storno_at', 'e.kunden_id', 'e.anforderungen',
                'k.kundenname', 'o.objektname', 'd.menge as soll_menge',
                DB::raw("NULLIF(TRIM(COALESCE(NULLIF(a.bereich, ''), k.bereich)), '') as bereich"));
        if ($hasDayAnf) $q->addSelect('d.anforderungen as plan_anforderungen');
        $e = $q->first();
        if (!$e) return null;
        $anf = json_decode((string) ($e->anforderungen ?: ($e->plan_anforderungen ?? '')), true);
        $e->anf = is_array($anf) ? $anf : [];
        $e->assigned = self::t('einsatz_mitarbeiter')->where('einsatz_id', $einsatzId)->pluck('dienst_nr')->all();
        return $e;
    }

    /** Still changeable on the exchange: not started, not confirmed, not storno. */
    public static function isOpen(object $e): bool
    {
        if ($e->hms_bestaetigt === 'ja' || !empty($e->bestell_storno_at) || !$e->datum) return false;
        try {
            $start = Carbon::parse(substr((string) $e->datum, 0, 10) . ' ' . (substr((string) $e->ist_start, 0, 5) ?: '00:00'));
        } catch (\Throwable $ex) {
            return false;
        }
        return $start->isFuture();
    }

    /** Free places of an office-published shift (Soll − assigned). No Soll → 1 place. */
    public static function freePlaces(object $e): int
    {
        $soll = (int) round((float) ($e->soll_menge ?? 0));
        $taken = count($e->assigned);
        return $soll > 0 ? max(0, $soll - $taken) : ($taken > 0 ? 0 : 1);
    }

    public static function label(object $e): string
    {
        $d = $e->datum ? Carbon::parse($e->datum) : null;
        $wd = $d ? ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$d->dayOfWeek] . ' ' . $d->format('d.m.') : '';
        return trim($wd . ' · ' . substr((string) $e->ist_start, 0, 5) . '–' . substr((string) $e->ist_ende, 0, 5)
            . ' · ' . ($e->objektname ?: ($e->kundenname ?: '')), ' ·');
    }

    // ───────────────────────── matching ─────────────────────────

    private static function key($s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    }

    /** §34a level of a text in any spelling (1 Unterrichtung … 4 Fachkraft), null if none. */
    public static function q34Level($text): ?int
    {
        $t = ' ' . preg_replace('/[^a-z0-9äöüß§\/]+/u', ' ', self::key($text)) . ' ';
        if (preg_match('/ohne (§ ?)?34/u', $t)) return null;
        $lv = [];
        if (preg_match('/fachkraft|\bfk\b/u', $t)) $lv[] = 4;
        if (preg_match('/gssk|schutz und sicherheitskraft/u', $t)) $lv[] = 3;
        if (preg_match('/\bskp\b|sach ?kunde|sackunde|sma\/skp/u', $t)) $lv[] = 2;
        if (preg_match('/unterrichtung|\bu\b|sma\/u|34 ?a? ?u\b|§ ?34 ?u\b/u', $t)) $lv[] = 1;
        if (!$lv && preg_match('/34 ?a|§ ?34/u', $t)) $lv[] = 1;
        return $lv ? min($lv) : null;
    }

    /** ['q34' => ?int, 'names' => [catalog names required]] */
    public static function requiredQuals(array $anf, array $catalogKeys): array
    {
        $src = [$anf['qualifikation'] ?? ''];
        foreach ((array) ($anf['positionen'] ?? []) as $p) $src[] = is_array($p) ? ($p['qualifikation'] ?? '') : '';
        $q34 = null; $names = [];
        foreach ($src as $v) {
            foreach (explode(',', (string) $v) as $raw) {
                $k = self::key($raw);
                if ($k === '') continue;
                $lvl = self::q34Level($raw);
                if ($lvl !== null) { $q34 = $q34 === null ? $lvl : min($q34, $lvl); continue; }
                if (isset($catalogKeys[$k])) $names[$k] = true;   // free text outside the catalog: not checkable
            }
        }
        return ['q34' => $q34, 'names' => array_keys($names)];
    }

    /**
     * Stadium/DFB shift = the Anforderungen ask for "DFB"/"Stadion" (any
     * spelling) or for an item of a catalog list whose name contains
     * DFB/Stadion (e.g. "DFB Schulung" → "Modul 6 - Einlass"). Decides where
     * the stadium ban (stadion_gesperrt) applies — user decision: per shift,
     * not per Bereich (a Fußstreife in "Sicherheit" is no stadium shift).
     */
    public static function isStadiumShift($anf): bool
    {
        if (!is_array($anf) || !$anf) return false;
        $src = [$anf['qualifikation'] ?? ''];
        foreach ((array) ($anf['positionen'] ?? []) as $p) $src[] = is_array($p) ? ($p['qualifikation'] ?? '') : '';
        $tokens = [];
        foreach ($src as $v) foreach (explode(',', (string) $v) as $raw) { $k = self::key($raw); if ($k !== '') $tokens[] = $k; }
        if (!$tokens) return false;
        foreach ($tokens as $k) if (preg_match('/\bdfb\b|stadion/u', $k)) return true;
        if (!Schema::connection('tenant')->hasTable('bereich_qualifikationen')) return false;
        $lists = self::t('bereich_qualifikationen')->whereNull('parent_id')
            ->where(fn ($q) => $q->where('name', 'like', '%DFB%')->orWhere('name', 'like', '%tadion%'))->pluck('id')->all();
        if (!$lists) return false;
        $items = array_map([self::class, 'key'], self::t('bereich_qualifikationen')->whereIn('parent_id', $lists)->pluck('name')->all());
        return (bool) array_intersect($tokens, $items);
    }

    public static function requiredGender(array $anf): array
    {
        $mix = $anf['geschlecht_mix'] ?? null;
        if (is_array($mix) && ((int) ($mix['maennlich'] ?? 0) > 0 || (int) ($mix['weiblich'] ?? 0) > 0)) {
            return ['m' => (int) ($mix['maennlich'] ?? 0), 'w' => (int) ($mix['weiblich'] ?? 0)];
        }
        $m = $w = 0;
        foreach ((array) ($anf['positionen'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $n = max(1, (int) ($p['anzahl'] ?? 1));
            if (($p['geschlecht'] ?? '') === 'maennlich') $m += $n;
            if (($p['geschlecht'] ?? '') === 'weiblich') $w += $n;
        }
        return ['m' => $m, 'w' => $w];
    }

    /**
     * Suitable workers for a shift. $only limits the check to these dienst_nr,
     * $exclude drops one (the worker handing the shift over).
     *
     * @return \Illuminate\Support\Collection of mitarbeiter rows (dienst_nr, vorname, name, email, …)
     */
    public static function eligibleWorkers(object $e, ?array $only = null, ?string $exclude = null)
    {
        $sm = Schema::connection('tenant');
        $cols = ['id', 'dienst_nr', 'vorname', 'name', 'email', 'foto', 'bereich', 'arbeitsstunden', 'arbeitsstunden_typ'];
        foreach (['bereiche', 'geschlecht', 'qualifikationen', 'stadion_gesperrt'] as $c) if ($sm->hasColumn('mitarbeiter', $c)) $cols[] = $c;
        $workers = self::t('mitarbeiter')->where('aktiv', 1)
            ->when($only !== null, fn ($q) => $q->whereIn('dienst_nr', $only ?: ['__none__']))
            ->whereNotIn('dienst_nr', array_merge($e->assigned, $exclude ? [$exclude] : []))
            ->get($cols);
        if ($workers->isEmpty()) return $workers;

        // Bereich (pool semantics: untagged worker fits every Bereich).
        $bereich = $e->bereich ? self::key($e->bereich) : null;
        if ($bereich) {
            $workers = $workers->filter(function ($w) use ($bereich) {
                $tags = array_map([self::class, 'key'], array_merge(
                    explode(',', (string) ($w->bereich ?? '')),
                    (array) (json_decode((string) ($w->bereiche ?? ''), true) ?: [])
                ));
                $tags = array_filter($tags);
                return !$tags || in_array($bereich, $tags, true);
            });
        }

        // Gender — only when one gender alone is required.
        $g = self::requiredGender($e->anf);
        if (($g['m'] > 0) !== ($g['w'] > 0)) {
            $need = $g['m'] > 0 ? ['m'] : ['w', 'f'];
            $workers = $workers->filter(fn ($w) => in_array(mb_strtolower(mb_substr(trim((string) ($w->geschlecht ?? '')), 0, 1)), $need, true));
        }

        // Qualifications.
        $catalog = $sm->hasTable('bereich_qualifikationen')
            ? self::t('bereich_qualifikationen')->whereNotNull('parent_id')->where('typ', 'qualifikation')->pluck('name', 'id')->all() : [];
        $req = self::requiredQuals($e->anf, array_flip(array_map([self::class, 'key'], $catalog)));
        if ($req['q34'] !== null || $req['names']) {
            $workers = $workers->filter(function ($w) use ($req, $catalog) {
                $own = array_filter(array_map(fn ($i) => $catalog[(int) $i] ?? null, (array) (json_decode((string) ($w->qualifikationen ?? ''), true) ?: [])));
                if ($req['q34'] !== null) {
                    $lvl = max(array_merge([0], array_map(fn ($n) => self::q34Level($n) ?? 0, $own)));
                    if ($lvl < $req['q34']) return false;
                }
                $ownKeys = array_map([self::class, 'key'], $own);
                foreach ($req['names'] as $n) if (!in_array($n, $ownKeys, true)) return false;
                return true;
            });
        }

        // Stadium ban — only on stadium/DFB shifts.
        if (self::isStadiumShift($e->anf)) $workers = $workers->filter(fn ($w) => empty($w->stadion_gesperrt));
        if ($workers->isEmpty()) return $workers->values();

        // Overlapping shift / absence — the planner's own checks.
        try {
            $task = (object) ['datum' => $e->datum, 'einsatz_id' => $e->einsatz_id, 'ist_start' => $e->ist_start, 'ist_ende' => $e->ist_ende];
            $m = new \ReflectionMethod(TaskController::class, 'attachWorkerWarnings');
            $m->setAccessible(true);
            $workers = $m->invoke(new TaskController(), $workers->values(), $task);
            $workers = $workers->filter(fn ($w) => !collect($w->warnings ?? [])->contains(fn ($x) => in_array($x['type'] ?? '', ['assignment', 'absence'], true)));
        } catch (\Throwable $ex) {
            Log::warning('Schichtboerse conflict check failed', ['einsatz' => $e->einsatz_id, 'error' => $ex->getMessage()]);
        }
        return $workers->values();
    }

    public static function isEligible(object $e, string $dienstNr, ?string $exclude = null): bool
    {
        return self::eligibleWorkers($e, [$dienstNr], $exclude)->isNotEmpty();
    }

    /** In-app row + device push to workers — no e-mail (exchange offers would spam the inbox). */
    public static function notifyWorkers($workers, string $type, string $title, string $body, array $payload = []): void
    {
        $tenantId = null;
        try { $tenantId = TenantService::current()->id ?? null; } catch (\Throwable $ex) { /* optional */ }
        foreach ($workers as $w) {
            try {
                self::t('worker_notifications')->insert([
                    'mitarbeiter_id' => $w->id, 'dienst_nr' => $w->dienst_nr, 'type' => $type, 'title' => $title,
                    'body' => $body, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'gelesen' => 0, 'created_at' => now(),
                ]);
                if ($tenantId) \App\Jobs\SendWorkerPush::dispatch((int) $tenantId, (string) $w->dienst_nr, $title, $body, 'plan');
            } catch (\Throwable $ex) {
                Log::warning('Schichtboerse notify failed', ['dienst_nr' => $w->dienst_nr, 'error' => $ex->getMessage()]);
            }
        }
    }
}
