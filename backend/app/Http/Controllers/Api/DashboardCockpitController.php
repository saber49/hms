<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Support\Aueg;
use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Übersicht "command center": what is happening NOW, what needs action, and
 * how well the next 7 days are covered. Every block is gated by the module
 * permission and every query carries the user's BereichScope.
 *
 * GET /api/dashboard/cockpit
 */
class DashboardCockpitController extends Controller
{
    private const LATE_MINUTES = 10;

    public function index(Request $request)
    {
        $u = $request->user();
        $can = fn (string $k) => method_exists($u, 'hasPermission') && $u->hasPermission($k);
        $db = DB::connection('tenant');
        $s = Schema::connection('tenant');
        $now = Carbon::now('Europe/Berlin');
        $today = $now->toDateString();

        $out = ['jetzt' => $now->format('Y-m-d H:i'), 'heute' => null, 'woche' => [], 'inbox' => []];
        $inbox = [];
        // $ziel = exact target in the app (page + filter/date/record) — see dashGo() in dashboard.js.
        $add = function (string $key, string $level, string $icon, int $count, string $title, string $cta, array $ziel, ?string $hint = null) use (&$inbox) {
            if ($count > 0) $inbox[] = compact('key', 'level', 'icon', 'count', 'title', 'cta', 'ziel', 'hint');
        };

        $allowed = BereichScope::for($u);
        $eScope = BereichScope::inList($allowed, BereichScope::derivedExprSub('e'));
        [$wSql, $wParams] = BereichScope::workerPredicate($u, 'm');
        $storno = $s->hasColumn('einsaetze', 'bestell_storno_at') ? 'AND e.bestell_storno_at IS NULL' : '';

        // ── Shifts today … +6 days: Soll (order day) vs. assigned ─────────────
        if ($can('auftraege.dispo.view')) {
            $end = $now->copy()->addDays(6)->toDateString();
            $rows = $db->select("
                SELECT e.einsatz_id, e.datum, e.ist_start, e.ist_ende, e.nu_id,
                       k.kundenname, o.objektname,
                       (SELECT COUNT(*) FROM einsatz_mitarbeiter em WHERE em.einsatz_id = e.einsatz_id) AS ist,
                       (SELECT d.menge FROM bestellungen b
                          JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                         WHERE b.bestell_id = e.bestell_id
                      ORDER BY (d.tag_nr <=> e.tag_nr) DESC, d.tag_nr LIMIT 1) AS soll
                  FROM einsaetze e
                  LEFT JOIN kunden k  ON k.kunde_id = e.kunden_id
                  LEFT JOIN objekte o ON o.obj_id   = e.obj_id
                 WHERE e.datum BETWEEN ? AND ? {$storno} AND {$eScope[0]}
              ORDER BY e.datum, COALESCE(e.ist_start, '99:99')
            ", array_merge([$today, $end], $eScope[1]));

            $state = function ($r) {
                if (!empty($r->nu_id)) return 'nu';
                $ist = (int) $r->ist; $soll = max(1, (int) ($r->soll ?? 1));
                return $ist === 0 ? 'offen' : ($ist < $soll ? 'teilweise' : 'besetzt');
            };
            $row = fn ($r) => [
                'einsatz_id' => $r->einsatz_id, 'start' => substr((string) $r->ist_start, 0, 5), 'ende' => substr((string) $r->ist_ende, 0, 5),
                'kunde' => $r->kundenname, 'objekt' => $r->objektname, 'status' => $r->status,
                'ist' => (int) $r->ist, 'soll' => max(1, (int) ($r->soll ?? 1)),
            ];
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $now->copy()->addDays($i);
                $days[$d->toDateString()] = ['datum' => $d->toDateString(), 'gesamt' => 0, 'besetzt' => 0, 'nu' => 0, 'teilweise' => 0, 'offen' => 0, 'liste' => []];
            }
            $todayRows = [];
            foreach ($rows as $r) {
                $st = $state($r);
                $r->status = $st;
                $key = substr((string) $r->datum, 0, 10);
                if (!isset($days[$key])) continue;
                $days[$key]['gesamt']++;
                $days[$key][$st]++;
                $days[$key]['liste'][] = $row($r);
                if ($key === $today) $todayRows[] = $r;
            }
            $out['woche'] = array_values($days);

            // Live: who is clocked in, who is late (start passed, no clock-in).
            $ids = array_map(fn ($r) => $r->einsatz_id, $todayRows);
            $assigned = $ids ? $db->table('einsatz_mitarbeiter as em')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'em.dienst_nr')
                ->whereIn('em.einsatz_id', $ids)->whereRaw($wSql, $wParams)
                ->get(['em.einsatz_id', 'em.dienst_nr', 'm.vorname', 'm.name']) : collect();
            $clocked = $ids ? $db->table('zeiterfassung')->whereIn('einsatz_id', $ids)
                ->get(['einsatz_id', 'dienst_nr', 'clock_out'])
                ->groupBy(fn ($z) => $z->einsatz_id . '|' . $z->dienst_nr) : collect();
            $byId = collect($todayRows)->keyBy('einsatz_id');
            $late = [];
            foreach ($assigned as $a) {
                $e = $byId[$a->einsatz_id] ?? null;
                if (!$e || !$e->ist_start || $clocked->has($a->einsatz_id . '|' . $a->dienst_nr)) continue;
                $start = Carbon::parse($today . ' ' . substr((string) $e->ist_start, 0, 5), 'Europe/Berlin');
                $endT = $e->ist_ende ? Carbon::parse($today . ' ' . substr((string) $e->ist_ende, 0, 5), 'Europe/Berlin') : $start->copy()->addHours(8);
                if ($endT->lte($start)) $endT->addDay();
                if ($now->gt($start->copy()->addMinutes(self::LATE_MINUTES)) && $now->lt($endT)) {
                    $late[] = [
                        'name' => trim(($a->vorname ?? '') . ' ' . ($a->name ?? '')) ?: $a->dienst_nr,
                        'start' => substr((string) $e->ist_start, 0, 5),
                        'kunde' => $e->kundenname, 'objekt' => $e->objektname, 'einsatz_id' => $e->einsatz_id,
                    ];
                }
            }
            // Clocked in right now (any shift; clock_in is stored in UTC).
            $imDienst = (int) $db->table('zeiterfassung as z')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'z.dienst_nr')
                ->whereNull('z.clock_out')->where('z.clock_in', '>=', now('UTC')->subHours(18))
                ->whereRaw($wSql, $wParams)->count();

            $t = $days[$today];
            $problems = array_values(array_filter($todayRows, fn ($r) => in_array($r->status, ['offen', 'teilweise'], true)));
            $out['heute'] = [
                'gesamt' => $t['gesamt'], 'besetzt' => $t['besetzt'] + $t['nu'], 'teilweise' => $t['teilweise'], 'offen' => $t['offen'],
                'im_dienst' => $imDienst, 'verspaetet' => count($late), 'verspaetet_liste' => $late,
                // All of today's shifts — the "Jetzt" tiles open a filtered list of these.
                'liste' => array_map($row, $todayRows),
                'probleme' => array_map($row, array_slice($problems, 0, 8)),
            ];

            $open7 = array_sum(array_map(fn ($d) => $d['offen'] + $d['teilweise'], $days));
            $firstOpen = collect($days)->first(fn ($d) => $d['offen'] + $d['teilweise'] > 0)['datum'] ?? $today;
            $add('unbesetzt', 'danger', 'person_off', $open7, 'Schichten in den nächsten 7 Tagen nicht voll besetzt', 'Planen',
                ['page' => 'tasks', 'datum' => $firstOpen]);

            // Worked (staff or NU) but still 'ausstehend' after 7 days → missing in
            // revenue/Controlling (only hms_bestaetigt = 'ja' counts). Unstaffed
            // past shifts are deliberately NOT counted (they were never worked).
            $staleQ = $db->table('einsaetze as e')->where('e.hms_bestaetigt', 'ausstehend')
                ->whereDate('e.datum', '<', $now->copy()->subDays(7)->toDateString())
                ->where(fn ($q) => $q->whereNotNull('e.nu_id')
                    ->orWhereExists(fn ($x) => $x->from('einsatz_mitarbeiter as em')->whereColumn('em.einsatz_id', 'e.einsatz_id')))
                ->whereRaw($eScope[0] . ' ' . $storno, $eScope[1]);
            $stale = (int) (clone $staleQ)->count();
            // Confirm happens in Planung (month view → "Bestätigen") — open the OLDEST affected month.
            $oldest = $stale ? substr((string) (clone $staleQ)->min('e.datum'), 0, 10) : null;
            $add('ausstehend', 'warning', 'hourglass_top', $stale, 'Geleistete Einsätze seit über 7 Tagen nicht bestätigt', 'Bestätigen',
                ['page' => 'tasks', 'ansicht' => 'month', 'datum' => $oldest], 'Fehlen in Umsatz und Auswertung' . ($oldest ? ' · ältester: ' . Carbon::parse($oldest)->format('d.m.Y') : ''));

            if ($s->hasTable('schicht_anfragen')) {
                $boerse = (int) $db->table('schicht_anfragen as a')->join('schicht_angebote as g', 'g.id', '=', 'a.angebot_id')
                    ->join('einsaetze as e', 'e.einsatz_id', '=', 'g.einsatz_id')
                    ->where('a.status', 'angefragt')->where('g.status', 'aktiv')->whereRaw($eScope[0], $eScope[1])->count();
                $add('boerse', 'info', 'swap_horiz', $boerse, 'Anfragen in der Schichtbörse', 'Entscheiden', ['page' => 'tasks', 'boerse' => 1]);
            }
        }

        // ── Orders ────────────────────────────────────────────────────────
        if ($can('auftraege.eingang.view')) {
            $bScope = BereichScope::inList($allowed, BereichScope::derivedExprSub('bestelleingang'));
            $neu = (int) $db->table('bestelleingang')->where('status', 'unbearbeitet')->whereRaw($bScope[0], $bScope[1])->count();
            $add('eingang', 'warning', 'inbox', $neu, 'Neue Bestelleingänge zu bearbeiten', 'Öffnen', ['page' => 'incoming_orders', 'status' => 'unbearbeitet']);
        }

        // ── Month-end approvals for the previous month ───────────────────
        if ($can('auftraege.monatsabschluss.view')) {
            $pm = $now->copy()->subMonthNoOverflow();
            $mName = $pm->locale('de')->isoFormat('MMMM');
            $base = fn () => $db->table('einsatz_mitarbeiter as em')->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
                ->whereBetween('e.datum', [$pm->copy()->startOfMonth()->toDateString(), $pm->copy()->endOfMonth()->toDateString()])
                ->whereRaw($eScope[0] . ' ' . $storno, $eScope[1]);
            if ($s->hasColumn('einsatz_mitarbeiter', 'kunden_freigabe')) {
                $add('kunden_freigabe', 'warning', 'fact_check', (int) $base()->where(fn ($q) => $q->whereNull('em.kunden_freigabe')->orWhere('em.kunden_freigabe', 0))->count(),
                    "Einsatzzeiten im {$mName} ohne Kunden-Freigabe", 'Freigeben', ['page' => 'monatsabschluss', 'mode' => 'kunde', 'monat' => $pm->month, 'jahr' => $pm->year], 'Ohne Freigabe kein Leistungsnachweis');
            }
            if ($s->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
                $add('zeit_freigabe', 'info', 'schedule', (int) $base()->where(fn ($q) => $q->whereNull('em.zeit_freigabe')->orWhere('em.zeit_freigabe', 0))->count(),
                    "Einsatzzeiten im {$mName} ohne Mitarbeiter-Freigabe", 'Prüfen', ['page' => 'monatsabschluss', 'mode' => 'ma', 'monat' => $pm->month, 'jahr' => $pm->year]);
            }
        }

        // ── Staff ─────────────────────────────────────────────────────────
        if ($can('stammdaten.mitarbeiter.view')) {
            if ($s->hasTable('mitarbeiter_abwesenheiten') && $s->hasColumn('mitarbeiter_abwesenheiten', 'status')) {
                $add('antraege', 'warning', 'event_busy', (int) $db->table('mitarbeiter_abwesenheiten as x')->join('mitarbeiter as m', 'm.dienst_nr', '=', 'x.dienst_nr')
                    ->where('x.status', 'pending')->whereRaw($wSql, $wParams)->count(), 'Urlaubs- und Abwesenheitsanträge warten auf Entscheidung', 'Entscheiden', ['page' => 'absences', 'antraege' => 1]);
            }
            if ($s->hasTable('mitarbeiter_dokument_anfragen')) {
                $add('erneuerung', 'warning', 'upload_file', (int) $db->table('mitarbeiter_dokument_anfragen as x')->join('mitarbeiter as m', 'm.dienst_nr', '=', 'x.dienst_nr')
                    ->where('x.status', 'hochgeladen')->whereRaw($wSql, $wParams)->count(), 'Neue Dokumente von Mitarbeitern zu prüfen', 'Prüfen', ['page' => 'workers', 'ablauf' => 1]);
            }
            if ($s->hasTable('mitarbeiter_dokumente')) {
                $docs = fn () => $db->table('mitarbeiter_dokumente as x')->join('mitarbeiter as m', 'm.dienst_nr', '=', 'x.dienst_nr')
                    ->where('m.aktiv', 1)->whereNotNull('x.ablaufdatum')->whereRaw($wSql, $wParams);
                $add('dok_abgelaufen', 'danger', 'gpp_bad', (int) $docs()->whereDate('x.ablaufdatum', '<', $today)->count(),
                    'Dokumente aktiver Mitarbeiter sind abgelaufen', 'Prüfen', ['page' => 'workers', 'ablauf' => 1]);
                $add('dok_bald', 'info', 'badge', (int) $docs()->whereBetween('x.ablaufdatum', [$today, $now->copy()->addDays(30)->toDateString()])->count(),
                    'Dokumente laufen in den nächsten 30 Tagen ab', 'Ansehen', ['page' => 'workers', 'ablauf' => 1]);
            }
            // AÜG deadlines (only when the module is on).
            if (Aueg::enabled(TenantService::current()) && Aueg::ready()) {
                $cfg = Aueg::entleiher();
                if ($cfg) {
                    $vis = $db->table('mitarbeiter as m')->whereRaw($wSql, $wParams)->pluck('m.dienst_nr')->all();
                    $n = 0;
                    foreach (Aueg::dates(array_keys($cfg), $vis) as $kid => $byW) {
                        foreach ($byW as $dates) {
                            $st = Aueg::evaluate($dates, $today, $cfg[$kid]);
                            if ($st && ($st['status'] === 'ueberschritten' || $st['status'] === 'bald' || $st['geplant_ueber_frist'])) $n++;
                        }
                    }
                    $add('aueg', 'danger', 'gavel', $n, 'AÜG: Überlassungshöchstdauer erreicht oder bald erreicht', 'Ansehen', ['page' => 'aueg']);
                }
            }
        }

        // ── Own tasks (Aufgaben) ──────────────────────────────────────────
        if ($s->hasTable('aufgabe_empfaenger') && $s->hasTable('aufgaben')) {
            $mine = $db->table('aufgabe_empfaenger as r')->join('aufgaben as a', 'a.id', '=', 'r.aufgabe_id')
                ->where('r.typ', 'user')->where('r.user_id', $u->id)->where('r.status', '!=', 'erledigt');
            $overdue = (int) (clone $mine)->whereNotNull('a.faellig_am')->whereDate('a.faellig_am', '<', $today)->count();
            $add('aufgaben', $overdue ? 'danger' : 'info', 'task_alt', (int) $mine->count(),
                'Offene Aufgaben für Sie', 'Öffnen', ['page' => 'aufgaben'], $overdue ? "{$overdue} davon überfällig" : null);
        }

        // ── Sales follow-ups ──────────────────────────────────────────────
        // Lead-Modul: same definition as the Lead-Arbeitsbereich tabs (Crm::leadZeilen).
        if ($can('stammdaten.interessenten.view') && \App\Support\Crm::ready()) {
            $z = \App\Support\Crm::leadZaehler($u);
            $ueber = $z['ueberfaellig'];
            $heute = $z['heute'];
            $add('wiedervorlage', $ueber ? 'warning' : 'info', 'contact_phone', $ueber + $heute, 'Leads heute fällig / überfällig', 'Öffnen',
                ['page' => 'interessenten', 'overdue' => $ueber ? 1 : 0, 'tab' => $ueber ? 'ueberfaellig' : 'heute'], $ueber ? "{$ueber} überfällig" : null);
        }

        // ── Master data that silently breaks billing ─────────────────────
        if ($can('stammdaten.objekte.view') && $s->hasTable('artikel')) {
            [$aSql, $aParams] = BereichScope::artikelPredicate($u, 'artikel');
            $add('ohne_preis', 'warning', 'sell', (int) $db->table('artikel')->where('aktiv', 1)
                ->where(fn ($q) => $q->where('preis_kunde', 0)->orWhereNull('preis_kunde'))->whereRaw($aSql, $aParams)->count(),
                'Artikel ohne Kundenpreis — Umsätze werden nicht berechnet', 'Preise pflegen', ['page' => 'objects']);
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($inbox, fn ($a, $b) => [$rank[$a['level']], -$a['count']] <=> [$rank[$b['level']], -$b['count']]);
        $out['inbox'] = $inbox;
        $out['can'] = ['dispo' => $can('auftraege.dispo.view')];
        return response()->json($out);
    }
}
