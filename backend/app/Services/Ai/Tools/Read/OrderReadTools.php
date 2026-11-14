<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\AbrechnungDataService;
use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class OrderReadTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'aktive_bestellungen' => true,
            'bestelleingang_status' => true,
            'bestellungen_status' => true,
            'bestellung_details' => true,
            'abrechnung_bestellung' => true,
            'abrechnung_monat' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('aktive_bestellungen',
                'Listet derzeit laufende Bestellungen (Soll-Stunden gegen Ist-Stunden im Zeitraum).',
                ['limit' => ['type' => 'integer']], []),

            $this->fn('bestelleingang_status',
                'Status der eingehenden Aufträge (Bestelleingang) — wie viele unbearbeitet / in Bearbeitung / abgeschlossen, optional für einen Monat.',
                [
                    'monat' => ['type' => 'integer'],
                    'jahr'  => ['type' => 'integer'],
                ], []),

            $this->fn('bestellungen_status',
                'Übersicht laufender und abgeschlossener Bestellungen (Aufträge), optional gefiltert nach Monat.',
                [
                    'monat' => ['type' => 'integer'],
                    'jahr'  => ['type' => 'integer'],
                    'kunden_id' => ['type' => 'string'],
                ], []),

            $this->fn('bestellung_details',
                'Details zu EINER Bestellung oder EINEM Bestelleingang per ID. '
                . 'Akzeptiert BEIDE Formate: B-YYYYMM-NNNN (Bestellung) und BE-YYYYMM-NNNN (Bestelleingang/Eingangs-ID). '
                . 'Zeigt Kunde, Objekt, Zeitraum, Status, Tage und die verknüpfte Bestellung bzw. den Eingang. '
                . 'IMMER dieses Tool nutzen, wenn der Nutzer eine konkrete B-… oder BE-… ID nennt.',
                ['id' => ['type' => 'string', 'description' => 'B-YYYYMM-NNNN oder BE-YYYYMM-NNNN']],
                ['id']),

            $this->fn('abrechnung_bestellung',
                'Abrechnungs-Datensatz EINER Bestellung — EXAKT dieselben Zahlen wie Kunden-PDF und Controlling '
                . '(AbrechnungDataService). Pro zugewiesenem Mitarbeiter/NU: worker_hours (bezahlte Zeit), '
                . 'kundenstunden (fakturierbare Zeit — NICHT identisch mit worker_hours!), preis_kunde, '
                . 'abrechnungsmodell (Pro Stunde / Pro Einsatz / Monatspauschale), einnahmen, aufschlag '
                . '(Zuschlag-Anteil) und ma_kosten bzw. nu_kosten. '
                . 'Nutze IMMER dieses Tool für Fragen zu Rechnung/PDF/Abrechnung/Verdienst einer Bestellung — '
                . 'nicht die Rohwerte aus einsaetze zusammenzählen.',
                ['bestell_id' => ['type' => 'string', 'description' => 'Pflicht — z.B. "B-202605-4493"']],
                ['bestell_id']),

            $this->fn('abrechnung_monat',
                'Monatsabschluss-Datensatz für einen Monat — dieselben Zeilen und Summen wie die '
                . 'Monatsabschluss-Ansicht (AbrechnungDataService::completedAssignments): bestätigte '
                . 'Einsatz-Mitarbeiter + NU-Zuweisungen inkl. Stunden, Pausen und Freigabe-Status. '
                . 'Optional auf einen Kunden eingrenzbar. Für Umsatz/Kosten-Summen zusätzlich get_kpi nutzen.',
                [
                    'monat'     => ['type' => 'integer', 'description' => 'Pflicht — 1–12'],
                    'jahr'      => ['type' => 'integer', 'description' => 'Pflicht — z.B. 2026'],
                    'kunden_id' => ['type' => 'string', 'description' => 'optional — nur diesen Kunden'],
                    'dienst_nr' => ['type' => 'string', 'description' => 'optional — nur dieser Mitarbeiter'],
                ], ['monat','jahr']),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'aktive_bestellungen' => $this->aktiveBestellungen($args),
                'bestelleingang_status' => $this->bestelleingangStatus($args),
                'bestellungen_status' => $this->bestellungenStatus($args),
                'bestellung_details' => $this->bestellungDetails($args),
                'abrechnung_bestellung' => $this->abrechnungBestellung($args),
                'abrechnung_monat' => $this->abrechnungMonat($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function aktiveBestellungen(array $a): array
    {
        $limit = max(1, min(20, (int) ($a['limit'] ?? 10)));
        $where  = ['COALESCE(b.datum_bis, b.datum_von) >= CURDATE()'];
        $params = [];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('b'), $where, $params);
        $rows = DB::connection('tenant')->select("
            SELECT b.bestell_id, b.datum_von, b.datum_bis, b.soll_std_betrag,
                   k.kundenname, o.objektname
              FROM bestellungen b
              LEFT JOIN kunden  k ON b.kunden_id=k.kunde_id
              LEFT JOIN objekte o ON b.obj_id=o.obj_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY b.datum_von ASC
             LIMIT {$limit}
        ", $params);
        return ['bestellungen' => $rows ?: []];
    }

    private function bestelleingangStatus(array $a): array
    {
        $where  = '1=1';
        $params = [];
        if (!empty($a['monat'])) { $where .= ' AND monat = ?'; $params[] = (int) $a['monat']; }
        if (!empty($a['jahr']))  { $where .= ' AND jahr  = ?'; $params[] = (int) $a['jahr'];  }
        $whereArr = [$where];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('bestelleingang'), $whereArr, $params);
        $where = implode(' AND ', $whereArr);

        $rows = DB::connection('tenant')->select("
            SELECT COALESCE(status, 'unbekannt') AS status, COUNT(*) AS anzahl
              FROM bestelleingang
             WHERE {$where}
          GROUP BY status
          ORDER BY anzahl DESC
        ", $params);

        $total = array_sum(array_map(fn($r) => (int) $r->anzahl, $rows));
        return [
            'filter' => [
                'monat' => $a['monat'] ?? null,
                'jahr'  => $a['jahr']  ?? null,
            ],
            'gesamt' => $total,
            'nach_status' => array_map(fn($r) => [
                'status' => $r->status,
                'anzahl' => (int) $r->anzahl,
            ], $rows),
        ];
    }

    private function bestellungenStatus(array $a): array
    {
        $where  = '1=1';
        $params = [];
        if (!empty($a['monat']))     { $where .= ' AND monat = ?';     $params[] = (int) $a['monat']; }
        if (!empty($a['jahr']))      { $where .= ' AND jahr  = ?';     $params[] = (int) $a['jahr'];  }
        if (!empty($a['kunden_id'])) { $where .= ' AND kunden_id = ?'; $params[] = $a['kunden_id'];   }
        $whereArr = [$where];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('bestellungen'), $whereArr, $params);
        $where = implode(' AND ', $whereArr);

        $rows = DB::connection('tenant')->select("
            SELECT COALESCE(status, 'unbekannt') AS status,
                   COUNT(*) AS anzahl,
                   COALESCE(SUM(soll_std_betrag), 0) AS soll_stunden
              FROM bestellungen
             WHERE {$where}
          GROUP BY status
          ORDER BY anzahl DESC
        ", $params);

        return [
            'filter' => [
                'monat'     => $a['monat']     ?? null,
                'jahr'      => $a['jahr']      ?? null,
                'kunden_id' => $a['kunden_id'] ?? null,
            ],
            'nach_status' => array_map(fn($r) => [
                'status'        => $r->status,
                'anzahl'        => (int) $r->anzahl,
                'soll_stunden'  => round((float) $r->soll_stunden, 2),
            ], $rows),
        ];
    }

    /**
     * Single-order lookup by ID — accepts BOTH a bestellung ID (B-…) and
     * a bestelleingang ID (BE-…). Users constantly paste the BE-… ID
     * from the Bestelleingang list, and without this tool the LLM had no
     * way to resolve it (it would just answer "nicht gefunden" although
     * the entry clearly exists in the UI).
     */
    private function bestellungDetails(array $a): array
    {
        // Tolerant arg keys — the LLM sends 'bestell_id' or 'eingang_id'
        // about as often as the documented 'id'.
        $id = trim((string)($a['id'] ?? $a['bestell_id'] ?? $a['eingang_id'] ?? ''));
        if ($id === '') return ['error' => 'id ist Pflicht (B-… oder BE-…).'];

        $db = DB::connection('tenant');

        // Bestellung: direct hit or via its eingang_id link.
        $b = $db->table('bestellungen')->where('bestell_id', $id)->first()
          ?: $db->table('bestellungen')->where('eingang_id', $id)->first();

        // Bestelleingang: direct BE-… hit or via the bestellung's link.
        $eingangId = stripos($id, 'BE-') === 0 ? $id : ($b->eingang_id ?? null);
        $be = $eingangId
            ? $db->table('bestelleingang')->where('eingang_id', $eingangId)->first()
            : null;

        if (!$b && !$be) {
            return ['error' => "Keine Bestellung und kein Bestelleingang mit ID '{$id}' gefunden. "
                . 'Format prüfen: B-YYYYMM-NNNN (Bestellung) oder BE-YYYYMM-NNNN (Bestelleingang).'];
        }

        // Bereich gate AFTER resolution — "nicht gefunden" is returned
        // instead of a 403 so a restricted user cannot probe IDs to
        // discover which orders exist outside his Bereich.
        $user = request()->user();
        if ($b && !BereichScope::allowsBestellung($user, $b->bestell_id)) {
            return ['error' => "Keine Bestellung mit ID '{$id}' gefunden."];
        }
        if (!$b && $be && !BereichScope::allowsEingang($user, $be->eingang_id)) {
            return ['error' => "Kein Bestelleingang mit ID '{$id}' gefunden."];
        }

        $kundenId = $b->kunden_id ?? $be->kunden_id ?? null;
        $objId    = $b->obj_id    ?? $be->obj_id    ?? null;
        $kunde  = $kundenId ? $db->table('kunden')->where('kunde_id', $kundenId)->value('kundenname') : null;
        $objekt = $objId    ? $db->table('objekte')->where('obj_id', $objId)->value('objektname')     : null;

        $out = [
            'kunde'  => $kunde  ? "{$kundenId} — {$kunde}"  : $kundenId,
            'objekt' => $objekt ? "{$objId} — {$objekt}"    : $objId,
        ];

        if ($be) {
            $tage = $db->table('bestelleingang_tage')
                ->where('eingang_id', $be->eingang_id)
                ->selectRaw('COUNT(*) AS anzahl, MIN(datum) AS von, MAX(datum) AS bis')
                ->first();
            $out['bestelleingang'] = [
                'eingang_id' => $be->eingang_id,
                'status'     => $be->status ?? null,
                'monat'      => $be->monat  ?? null,
                'jahr'       => $be->jahr   ?? null,
                'tage'       => (int) ($tage->anzahl ?? 0),
                'zeitraum'   => ($tage->von ?? null) ? ($tage->von . ' bis ' . $tage->bis) : null,
            ];
        }

        if ($b) {
            $einsaetze = $db->table('einsaetze')->where('bestell_id', $b->bestell_id)->count();
            $out['bestellung'] = [
                'bestell_id' => $b->bestell_id,
                'eingang_id' => $b->eingang_id ?? null,
                'status'     => $b->status ?? null,
                'einsaetze'  => $einsaetze,
            ];
        } else {
            $out['hinweis'] = 'Zu diesem Bestelleingang existiert noch KEINE verknüpfte Bestellung — '
                . 'Einsätze können erst angelegt werden, wenn eine Bestellung existiert.';
        }

        return $out;
    }

    /**
     * The single source of truth for billing questions about an order:
     * AbrechnungDataService::confirmedOrderAssignments() — literally the
     * same rows the customer PDF and Controlling are built from.
     * worker_hours ≠ kundenstunden (paid vs. billable) — both are
     * returned so the model never confuses them.
     */
    private function abrechnungBestellung(array $a): array
    {
        $id = trim((string) ($a['bestell_id'] ?? $a['id'] ?? ''));
        if ($id === '') return ['error' => 'bestell_id erforderlich.'];

        if (!BereichScope::allowsBestellung(request()->user(), $id)) {
            return ['error' => "Bestellung {$id} nicht gefunden."];
        }

        $data = AbrechnungDataService::confirmedOrderAssignments($id);
        if (empty($data['workers']) && empty($data['nu_workers'])) {
            return [
                'bestell_id' => $id,
                'hinweis'    => 'Keine bestätigten Zuweisungen — nur Einsätze mit hms_bestaetigt="ja" werden abgerechnet.',
            ];
        }

        $fmt = function ($r, bool $nu = false) {
            return [
                'typ'             => $nu ? 'nu' : 'intern',
                'dienst_nr'       => $r->dienst_nr ?? null,
                'name'            => trim(($r->vorname ?? '') . ' ' . ($r->name ?? '')) ?: ($r->dienst_nr ?? $r->personal_nr ?? ''),
                'nu_firma'        => $r->nu_firmenname ?? null,
                'einsatz_id'      => $r->einsatz_id,
                'datum'           => $r->datum,
                'start'           => $r->ist_start,
                'ende'            => $r->ist_ende,
                'pause_minuten'   => (int) ($r->pause_minuten ?? 0),
                'worker_hours'    => round((float) ($r->worker_hours ?? 0), 2),
                'kundenstunden'   => round((float) ($r->kundenstunden ?? 0), 2),
                'abrechnungsmodell' => $r->abrechnungsmodell ?? null,
                'preis_kunde'     => round((float) ($r->preis_kunde ?? 0), 2),
                'einnahmen'       => round((float) ($r->einnahmen ?? 0), 2),
                'aufschlag'       => round((float) ($r->aufschlag ?? 0), 2),
                'kosten'          => round((float) ($r->ma_kosten ?? $r->nu_kosten ?? 0), 2),
                'stundenquelle'   => $r->stundenquelle ?? null,
                'inaktiv'         => isset($r->mitarbeiter_aktiv) ? ((int) $r->mitarbeiter_aktiv ? null : true) : null,
            ];
        };

        $rows = array_merge(
            array_map(fn ($r) => $fmt($r, false), $data['workers']),
            array_map(fn ($r) => $fmt($r, true),  $data['nu_workers'])
        );

        $sum = fn ($key) => round(array_sum(array_map(fn ($r) => (float) ($r[$key] ?? 0), $rows)), 2);

        return [
            'bestell_id'      => $id,
            'zeilen'          => count($rows),
            'totals'          => [
                'worker_hours'  => $sum('worker_hours'),
                'kundenstunden' => $sum('kundenstunden'),
                'einnahmen'     => $sum('einnahmen'),
                'aufschlag'     => $sum('aufschlag'),
                'kosten'        => $sum('kosten'),
            ],
            'zuweisungen'     => $rows,
        ];
    }

    /**
     * Month-close dataset — same rows/totals the Monatsabschluss UI
     * shows. Rows are summarised compactly and capped so big months
     * stay inside the token budget; the totals are always exact.
     */
    private function abrechnungMonat(array $a): array
    {
        $user = request()->user();
        $monat = (int) ($a['monat'] ?? 0);
        $jahr  = (int) ($a['jahr'] ?? 0);
        if ($monat < 1 || $monat > 12 || $jahr < 2000 || $jahr > 2100) {
            return ['error' => 'monat (1–12) und jahr sind Pflicht.'];
        }

        $start = sprintf('%04d-%02d-01', $jahr, $monat);
        $end   = date('Y-m-t', strtotime($start));

        $data = AbrechnungDataService::completedAssignments($user, $start, $end);

        $kunde = trim((string) ($a['kunden_id'] ?? ''));
        $ma    = trim((string) ($a['dienst_nr'] ?? ''));

        $entries = $data['entries'];
        $nuEntries = $data['nu_entries'];
        if ($kunde !== '') {
            $kl = mb_strtolower($kunde);
            $entries = array_values(array_filter($entries, fn ($r) =>
                str_contains(mb_strtolower((string) ($r->kundenname ?? '')), $kl)
                || str_contains(mb_strtolower((string) ($r->dienst_nr ?? '')), $kl)
                || str_contains(mb_strtolower((string) ($r->bestell_id ?? '')), $kl)));
            $nuEntries = array_values(array_filter($nuEntries, fn ($r) =>
                str_contains(mb_strtolower((string) ($r->kundenname ?? '')), $kl)
                || str_contains(mb_strtolower((string) ($r->bestell_id ?? '')), $kl)));
        }
        if ($ma !== '') {
            $ml = mb_strtolower($ma);
            $entries = array_values(array_filter($entries, fn ($r) =>
                str_contains(mb_strtolower((string) ($r->dienst_nr ?? '')), $ml)
                || str_contains(mb_strtolower(($r->vorname ?? '') . ' ' . ($r->name ?? '')), $ml)));
        }

        $cap = 60;
        $fmt = fn ($r) => [
            'dienst_nr'   => $r->dienst_nr ?? null,
            'name'        => trim(($r->vorname ?? '') . ' ' . ($r->name ?? '')),
            'datum'       => $r->datum,
            'einsatz_id'  => $r->einsatz_id,
            'kunde'       => $r->kundenname ?? null,
            'ist_std'     => $r->ist_std !== null && $r->ist_std !== '' && (float) $r->ist_std > 0
                ? (float) $r->ist_std
                : ($r->ist_std_menge ? (float) $r->ist_std_menge : 0),
            'quelle'      => ($r->ist_std !== null && (float) $r->ist_std > 0) ? 'ist' : 'plan',
            'pause'       => (int) ($r->pause_minuten ?? 0),
            'freigegeben' => (bool) ($r->zeit_freigabe ?? 0),
            'inaktiv'     => isset($r->mitarbeiter_aktiv) ? !((int) $r->mitarbeiter_aktiv) : null,
        ];

        return [
            'zeitraum'   => "$start bis $end",
            'totals'     => [
                'eintraege'        => count($entries),
                'stunden'          => round(array_sum(array_map(
                    fn ($r) => ($r->ist_std !== null && (float) $r->ist_std > 0)
                        ? (float) $r->ist_std
                        : ((float) ($r->ist_std_menge ?? 0)),
                    $entries)), 2),
                'mitarbeiter'      => count(array_unique(array_map(
                    fn ($r) => $r->dienst_nr, $entries))),
                'nu_eintraege'     => count($nuEntries),
                'nu_stunden'       => round(array_sum(array_map(
                    fn ($r) => ($r->ist_std !== null && (float) $r->ist_std > 0)
                        ? (float) $r->ist_std
                        : ((float) ($r->ist_std_menge ?? 0)),
                    $nuEntries)), 2),
                'nu_mitarbeiter'   => count(array_unique(array_map(
                    fn ($r) => $r->nu_worker_id ?? $r->personal_nr ?? null, $nuEntries))),
            ],
            'gekuerzt'         => count($entries) > $cap,
            'eintraege'        => array_map($fmt, array_slice($entries, 0, $cap)),
            'nu_eintraege'     => array_map(fn ($r) => [
                'nu_worker'  => trim(($r->vorname ?? '') . ' ' . ($r->name ?? '')),
                'nu_firma'   => $r->nu_firmenname ?? null,
                'datum'      => $r->datum,
                'einsatz_id' => $r->einsatz_id,
                'kunde'      => $r->kundenname ?? null,
                'ist_std'    => ($r->ist_std !== null && (float) $r->ist_std > 0)
                    ? (float) $r->ist_std
                    : ((float) ($r->ist_std_menge ?? 0)),
                'pause'      => (int) ($r->pause_minuten ?? 0),
            ], array_slice($nuEntries, 0, $cap)),
        ];
    }
}
