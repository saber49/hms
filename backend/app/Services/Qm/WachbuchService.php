<?php

namespace App\Services\Qm;

use App\Support\Qm\Qm;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wachbuch core — the ONLY place that writes qm_wb_blaetter / qm_wb_eintraege.
 *
 * Rules (spec §10 Revisionssicherheit):
 *  - entries are append-only; a correction is a new entry of art
 *    'berichtigung' pointing to the corrected one (ref_id),
 *  - every sheet carries a hash chain: hash = sha256(vorgaenger | payload),
 *  - timestamps come from the server; offline entries keep the device time
 *    in erfasst_am and are flagged nachgereicht.
 */
class WachbuchService
{
    public const ARTEN = [
        'dienstbeginn' => 'Dienstbeginn', 'dienstende' => 'Dienstende', 'notiz' => 'Notiz',
        'vorkommnis' => 'Vorkommnis', 'alarm' => 'Alarm', 'besucher' => 'Besucher',
        'lieferung' => 'Lieferung / Fremdfirma', 'schluessel' => 'Schlüssel', 'foto' => 'Foto',
        'uebergabe' => 'Übergabe', 'hinweis' => 'Hinweis', 'berichtigung' => 'Berichtigung',
    ];

    /** Arten a guard may write by hand (the rest is automatic). */
    public const MANUELL = ['notiz', 'vorkommnis', 'alarm', 'besucher', 'lieferung', 'schluessel', 'foto', 'hinweis', 'berichtigung'];

    public const FREIGABE_DURCH = ['objektleitung', 'auftraggeber', 'haustechnik', 'empfang', 'liste_kunde', 'ohne'];

    public static function aktiv(string $objId): bool
    {
        return Qm::ready() && Qm::boolSetting('modul_wachbuch') && (bool) Qm::objektEinstellung($objId)->wachbuch_aktiv;
    }

    // ---------------------------------------------------------------
    // Sheets
    // ---------------------------------------------------------------

    /** Sheet of a worker's shift (created on first use). */
    public static function blattFuerSchicht(string $einsatzId, string $dienstNr): ?object
    {
        $e = Qm::t('einsaetze')->where('einsatz_id', $einsatzId)->first(['einsatz_id', 'obj_id', 'datum']);
        if (!$e || !$e->obj_id) return null;
        $b = Qm::t('qm_wb_blaetter')->where('einsatz_id', $einsatzId)->where('dienst_nr', $dienstNr)->first();
        if ($b) return $b;
        try {
            $id = Qm::t('qm_wb_blaetter')->insertGetId([
                'obj_id' => $e->obj_id, 'einsatz_id' => $einsatzId, 'dienst_nr' => $dienstNr,
                'datum' => substr((string) $e->datum, 0, 10), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $ex) {
            // parallel request created it (unique einsatz_id + dienst_nr)
            return Qm::t('qm_wb_blaetter')->where('einsatz_id', $einsatzId)->where('dienst_nr', $dienstNr)->first();
        }
        return Qm::t('qm_wb_blaetter')->find($id);
    }

    /** Office sheet of an Objekt and day (entries written without a shift). */
    public static function bueroBlatt(string $objId, string $datum): object
    {
        $b = Qm::t('qm_wb_blaetter')->where('obj_id', $objId)->where('datum', $datum)
            ->whereNull('einsatz_id')->whereNull('dienst_nr')->first();
        if ($b) return $b;
        $id = Qm::t('qm_wb_blaetter')->insertGetId([
            'obj_id' => $objId, 'datum' => $datum, 'geoeffnet_am' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Qm::t('qm_wb_blaetter')->find($id);
    }

    // ---------------------------------------------------------------
    // Append (hash chain)
    // ---------------------------------------------------------------

    /**
     * Append one entry. $e: art, text, ort, details[], wichtig, ref_id,
     * erfasst_am (device time, ISO), client_uuid. $ctx: quelle, dienst_nr,
     * user_id, erfasst_von, auto. Returns the stored row (idempotent on
     * client_uuid).
     */
    public static function append(object $blatt, array $e, array $ctx): object
    {
        if (!empty($e['client_uuid'])) {
            $dup = Qm::t('qm_wb_eintraege')->where('client_uuid', $e['client_uuid'])->first();
            if ($dup) return $dup;
        }

        $id = DB::connection('tenant')->transaction(function () use ($blatt, $e, $ctx) {
            $b = Qm::t('qm_wb_blaetter')->where('id', $blatt->id)->lockForUpdate()->first();
            $nr = (int) $b->letzte_nr + 1;
            // Whole seconds: MySQL TIMESTAMP rounds fractions, the hash must match the stored value.
            $now = Carbon::now()->startOfSecond();
            $erfasst = !empty($e['erfasst_am']) ? Carbon::parse($e['erfasst_am']) : null;
            // Device time more than 2 minutes in the past = recorded offline.
            $nachgereicht = $erfasst && $erfasst->lt($now->copy()->subMinutes(2));
            $row = [
                'blatt_id'     => $b->id,
                'obj_id'       => $b->obj_id,
                'nr'           => $nr,
                'art'          => $e['art'],
                'zeit'         => $now,
                'erfasst_am'   => $erfasst,
                'nachgereicht' => $nachgereicht,
                'ort'          => isset($e['ort']) ? mb_substr((string) $e['ort'], 0, 200) : null,
                'text'         => $e['text'] ?? null,
                'details'      => !empty($e['details']) ? json_encode($e['details'], JSON_UNESCAPED_UNICODE) : null,
                'wichtig'      => !empty($e['wichtig']) || in_array($e['art'], ['vorkommnis', 'alarm'], true),
                'auto'         => !empty($ctx['auto']),
                'ref_id'       => $e['ref_id'] ?? null,
                'quelle'       => $ctx['quelle'] ?? 'app',
                'dienst_nr'    => $ctx['dienst_nr'] ?? null,
                'user_id'      => $ctx['user_id'] ?? null,
                'erfasst_von'  => $ctx['erfasst_von'] ?? null,
                'client_uuid'  => $e['client_uuid'] ?? null,
                'vorgaenger_hash' => $b->letzter_hash,
                'status'       => in_array($e['art'], ['vorkommnis', 'alarm'], true) ? 'offen' : null,
            ];
            $row['hash'] = self::hash($row);
            $id = Qm::t('qm_wb_eintraege')->insertGetId($row);
            Qm::t('qm_wb_blaetter')->where('id', $b->id)->update([
                'letzte_nr' => $nr, 'letzter_hash' => $row['hash'], 'updated_at' => $now,
                'geoeffnet_am' => $b->geoeffnet_am ?? $now,
            ]);
            return $id;
        });

        $row = Qm::t('qm_wb_eintraege')->find($id);
        Qm::changed('wachbuch', (string) $row->obj_id);
        if (in_array($row->art, ['vorkommnis', 'alarm'], true)) {
            QmNotify::vorkommnis($row);
        }
        return $row;
    }

    /** Canonical payload hash — fields that must never change. */
    public static function hash(array $r): string
    {
        $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;
        $str = fn ($v) => $v === null ? null : (string) $v;
        // MySQL re-formats JSON columns — hash a canonical (key-sorted) encoding.
        $canon = function ($v) use (&$canon) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v);
            return array_map($canon, $v);
        };
        $details = ($r['details'] ?? null) === null ? null : json_encode($canon(json_decode((string) $r['details'], true)), JSON_UNESCAPED_UNICODE);
        $payload = json_encode([
            (int) $r['nr'], (string) $r['art'], Carbon::parse($r['zeit'], 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'),
            $str($r['ort'] ?? null), $str($r['text'] ?? null), $details, (bool) $r['wichtig'],
            $int($r['ref_id'] ?? null), $str($r['dienst_nr'] ?? null), $int($r['user_id'] ?? null), $str($r['erfasst_von'] ?? null),
        ], JSON_UNESCAPED_UNICODE);
        return hash('sha256', ($r['vorgaenger_hash'] ?? '') . '|' . $payload);
    }

    /** Re-computes the chain of one sheet; returns the first broken nr or null. */
    public static function pruefeKette(int $blattId): ?int
    {
        $prev = null;
        foreach (Qm::t('qm_wb_eintraege')->where('blatt_id', $blattId)->orderBy('nr')->get() as $r) {
            $arr = (array) $r;
            if (($r->vorgaenger_hash ?? null) !== $prev || self::hash($arr) !== $r->hash) return (int) $r->nr;
            $prev = $r->hash;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Check-in / check-out automation (hooked from WorkerAppZeitController)
    // ---------------------------------------------------------------

    public static function nachCheckIn(string $einsatzId, string $dienstNr, Carbon $counted, Carbon $real, string $quelle = 'app'): void
    {
        try {
            $e = Qm::t('einsaetze')->where('einsatz_id', $einsatzId)->first(['obj_id', 'datum', 'ist_start']);
            if (!$e || !self::aktiv((string) $e->obj_id)) return;
            $blatt = self::blattFuerSchicht($einsatzId, $dienstNr);
            if (!$blatt) return;
            $name = Qm::workerName($dienstNr);
            $plan = $e->ist_start ? substr((string) $e->ist_start, 0, 5) : null;
            $abw = null;
            if ($plan) {
                $planAt = Carbon::parse(substr((string) $e->datum, 0, 10) . ' ' . $plan, 'Europe/Berlin');
                $min = (int) round(($real->getTimestamp() - $planAt->getTimestamp()) / 60);
                $abw = $min;
            }
            $text = 'Dienstbeginn ' . $real->copy()->setTimezone('Europe/Berlin')->format('H:i')
                . ($plan ? ' (Plan ' . $plan . ($abw ? ', ' . ($abw > 0 ? '+' : '') . $abw . ' min' : '') . ')' : '');
            $ctx = ['quelle' => $quelle, 'dienst_nr' => $dienstNr, 'erfasst_von' => $name, 'auto' => true];
            self::append($blatt, ['art' => 'dienstbeginn', 'text' => $text, 'details' => ['plan' => $plan, 'abweichung_min' => $abw]], $ctx);

            // Handover note of the previous shift on this Objekt.
            $vorher = Qm::t('qm_wb_blaetter')->where('obj_id', $e->obj_id)->where('id', '!=', $blatt->id)
                ->whereNotNull('geschlossen_am')->whereNotNull('hinweis_nachfolger')
                ->where('geschlossen_am', '>=', now()->subHours(36))
                ->orderByDesc('geschlossen_am')->first();
            if ($vorher && trim((string) $vorher->hinweis_nachfolger) !== '') {
                self::append($blatt, [
                    'art' => 'uebergabe',
                    'text' => trim((string) $vorher->hinweis_nachfolger),
                    'details' => ['von' => Qm::workerName($vorher->dienst_nr), 'blatt_id' => (int) $vorher->id],
                ], $ctx);
            }
        } catch (\Throwable $ex) {
            report($ex); // the clock-in itself must never fail because of the Wachbuch
        }
    }

    public static function nachCheckOut(string $einsatzId, string $dienstNr, Carbon $real, ?string $uebergabe = null, string $quelle = 'app'): void
    {
        try {
            $blatt = Qm::t('qm_wb_blaetter')->where('einsatz_id', $einsatzId)->where('dienst_nr', $dienstNr)->first();
            if (!$blatt || $blatt->geschlossen_am) return;
            $ctx = ['quelle' => $quelle, 'dienst_nr' => $dienstNr, 'erfasst_von' => Qm::workerName($dienstNr), 'auto' => true];
            $uebergabe = trim((string) $uebergabe);
            if ($uebergabe !== '') {
                self::append($blatt, ['art' => 'uebergabe', 'text' => mb_substr($uebergabe, 0, 2000), 'details' => ['an' => 'folgeschicht']], $ctx);
            }
            self::append($blatt, ['art' => 'dienstende', 'text' => 'Dienstende ' . $real->copy()->setTimezone('Europe/Berlin')->format('H:i')], $ctx);
            Qm::t('qm_wb_blaetter')->where('id', $blatt->id)->update([
                'geschlossen_am' => now(), 'hinweis_nachfolger' => $uebergabe !== '' ? mb_substr($uebergabe, 0, 2000) : null,
                'updated_at' => now(),
            ]);
            Qm::changed('wachbuch', (string) $blatt->obj_id);
        } catch (\Throwable $ex) {
            report($ex);
        }
    }

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    /** Entries of the given sheets, oldest first, with photos and corrections resolved. */
    public static function eintraege(array $blattIds): array
    {
        if (!$blattIds) return [];
        $rows = Qm::t('qm_wb_eintraege')->whereIn('blatt_id', $blattIds)->orderBy('zeit')->orderBy('nr')->get();
        $fotos = Qm::t('qm_wb_fotos')->whereIn('eintrag_id', $rows->pluck('id'))->get()->groupBy('eintrag_id');
        $berichtigt = $rows->where('art', 'berichtigung')->pluck('id', 'ref_id');
        return $rows->map(fn ($r) => self::format($r, $fotos[$r->id] ?? collect(), $berichtigt[$r->id] ?? null))->values()->all();
    }

    public static function format(object $r, $fotos = null, $berichtigtDurch = null): array
    {
        return [
            'id' => (int) $r->id, 'blatt_id' => (int) $r->blatt_id, 'obj_id' => $r->obj_id, 'nr' => (int) $r->nr, 'art' => $r->art,
            'art_label' => self::ARTEN[$r->art] ?? $r->art,
            'zeit' => Carbon::parse($r->zeit)->toIso8601String(),
            'erfasst_am' => $r->erfasst_am ? Carbon::parse($r->erfasst_am)->toIso8601String() : null,
            'nachgereicht' => (bool) $r->nachgereicht,
            'ort' => $r->ort, 'text' => $r->text, 'details' => json_decode($r->details ?? 'null', true) ?: (object) [],
            'wichtig' => (bool) $r->wichtig, 'auto' => (bool) $r->auto, 'ref_id' => $r->ref_id ? (int) $r->ref_id : null,
            'berichtigt_durch' => $berichtigtDurch ? (int) $berichtigtDurch : null,
            'quelle' => $r->quelle, 'dienst_nr' => $r->dienst_nr, 'erfasst_von' => $r->erfasst_von,
            'status' => $r->status,
            'fotos' => collect($fotos ?? [])->map(fn ($f) => [
                'id' => (int) $f->id, 'aufgenommen_am' => $f->aufgenommen_am,
                'lat' => $f->lat, 'lng' => $f->lng, 'genauigkeit_m' => $f->genauigkeit_m,
                'nicht_am_objekt' => (bool) $f->nicht_am_objekt,
            ])->values()->all(),
        ];
    }

    /**
     * Validate a manual entry (app, terminal, office). Returns [data, errors].
     */
    public static function pruefeEintrag(array $in): array
    {
        [$d, $err] = Qm::pruefe($in, [
            'art'         => 'required|in:' . implode(',', self::MANUELL),
            'text'        => 'nullable|string|max:4000',
            'ort'         => 'nullable|string|max:200',
            'wichtig'     => 'nullable|boolean',
            'ref_id'      => 'nullable|integer|required_if:art,berichtigung',
            'details'     => 'nullable|array',
            'erfasst_am'  => 'nullable|date',
            'client_uuid' => 'nullable|string|max:64',
        ]);
        if ($err) return [null, $err];
        $det = is_array($d['details'] ?? null) ? $d['details'] : [];
        $s = fn ($k, $n = 200) => isset($det[$k]) && is_scalar($det[$k]) ? mb_substr(trim((string) $det[$k]), 0, $n) : null;
        $b = fn ($k) => isset($det[$k]) ? filter_var($det[$k], FILTER_VALIDATE_BOOLEAN) : null;
        $clean = match ($d['art']) {
            'besucher'   => ['name' => $s('name'), 'firma' => $s('firma'), 'ziel' => $s('ziel'), 'kennzeichen' => $s('kennzeichen', 20),
                             'angemeldet' => $b('angemeldet'), 'ausweis_geprueft' => $b('ausweis_geprueft'), 'gegangen' => $s('gegangen', 5)],
            'lieferung'  => ['firma' => $s('firma'), 'person' => $s('person'), 'kennzeichen' => $s('kennzeichen', 20), 'zweck' => $s('zweck'),
                             'freigabe_durch' => in_array($det['freigabe_durch'] ?? null, self::FREIGABE_DURCH, true) ? $det['freigabe_durch'] : null,
                             'ausweis_geprueft' => $b('ausweis_geprueft')],
            'schluessel' => ['schluessel' => $s('schluessel'), 'aktion' => in_array($det['aktion'] ?? null, ['ausgabe', 'ruecknahme', 'bestand'], true) ? $det['aktion'] : null,
                             'an' => $s('an')],
            'vorkommnis', 'alarm' => ['massnahmen' => $s('massnahmen', 2000), 'benachrichtigt' => collect(is_array($det['benachrichtigt'] ?? null) ? $det['benachrichtigt'] : [])
                             ->take(10)->map(fn ($x) => ['stelle' => mb_substr((string) ($x['stelle'] ?? ''), 0, 80), 'zeit' => preg_match('/^\d{2}:\d{2}$/', (string) ($x['zeit'] ?? '')) ? $x['zeit'] : null])
                             ->filter(fn ($x) => $x['stelle'] !== '')->values()->all()],
            default      => [],
        };
        $clean = array_filter($clean, fn ($v) => $v !== null && $v !== '' && $v !== []);
        $e = [];
        if ($d['art'] === 'besucher' && empty($clean['ziel'])) $e['details.ziel'][] = 'Ziel / Ansprechpartner ist Pflicht.';
        if ($d['art'] === 'besucher' && empty($clean['name'])) $e['details.name'][] = 'Name ist Pflicht.';
        if ($d['art'] === 'lieferung' && empty($clean['firma'])) $e['details.firma'][] = 'Firma ist Pflicht.';
        if ($d['art'] === 'schluessel' && empty($clean['schluessel'])) $e['details.schluessel'][] = 'Schlüssel ist Pflicht.';
        if (in_array($d['art'], ['notiz', 'vorkommnis', 'alarm', 'hinweis', 'berichtigung'], true) && trim((string) ($d['text'] ?? '')) === '') {
            $e['text'][] = 'Text ist Pflicht.';
        }
        if ($e) return [null, $e];
        $d['details'] = $clean;
        return [$d, []];
    }

    /** A correction must point to an entry of the same Objekt that is not a correction itself. */
    public static function berichtigungOk(object $blatt, ?int $refId): bool
    {
        if (!$refId) return true;
        $ref = Qm::t('qm_wb_eintraege')->find($refId);
        return $ref && $ref->obj_id === $blatt->obj_id && $ref->art !== 'berichtigung';
    }
}
