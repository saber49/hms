<?php

namespace App\Services\Qm;

use App\Services\TenantService;
use App\Support\Qm\Qm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One Objekt and one Berlin operational day. Drafts are recomputed; approved
 * versions contain an immutable snapshot, not a view of later database edits.
 */
class WachbuchTagesbericht
{
    public static function aktivFuerTag(object $cfg, string $datum): bool
    {
        return (bool) ($cfg->bericht_pflicht ?? false) && $cfg->bericht_ab
            && $datum >= substr((string) $cfg->bericht_ab, 0, 10);
    }

    public static function grenzen(string $datum): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum, new \DateTimeZone('Europe/Berlin'));
        if (!$date || $date->format('Y-m-d') !== $datum) throw new \InvalidArgumentException('Ungültiges Berichtsdatum.');
        $start = CarbonImmutable::parse($datum . ' ' . Qm::tagBeginn(), 'Europe/Berlin');
        return [$start, $start->addDay()];
    }

    public static function entwurf(string $objId, string $datum): array
    {
        $tenant = TenantService::assertActive();
        [$start, $end] = self::grenzen($datum);
        $o = Qm::t('objekte as o')->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
            ->where('o.obj_id', $objId)->first(['o.obj_id', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'k.kundenname']);
        if (!$o) throw new \InvalidArgumentException('Objekt nicht gefunden.');
        $cfg = Qm::objektEinstellung($objId);
        $blaetter = Qm::t('qm_wb_blaetter')->where('obj_id', $objId)->where('datum', $datum)->orderBy('id')->get();
        $entries = collect(WachbuchService::eintraege($blaetter->pluck('id')->all()))
            ->where('obj_id', $objId)->values();
        $replaced = $entries->where('art', 'berichtigung')->pluck('ref_id')->filter()->all();
        $valid = $entries->reject(fn ($e) => in_array($e['id'], $replaced, true))->values();
        $issues = [];
        if ($cfg->bericht_pflicht && $blaetter->isEmpty()) $issues[] = 'Keine Wachbuchblätter für diesen Tag vorhanden.';
        foreach ($blaetter as $b) {
            if ($b->einsatz_id && !$b->geschlossen_am) $issues[] = 'Schichtblatt #' . $b->id . ' ist noch offen.';
            if (WachbuchService::pruefeKette((int) $b->id, $b) !== null) $issues[] = 'Prüfkette von Blatt #' . $b->id . ' ist verletzt.';
        }
        if ($entries->where('art', 'berichtigung')->isNotEmpty()) {
            $issues[] = 'Berichtigungen sind vorhanden; die korrigierten Angaben manuell prüfen.';
        }
        if ($valid->contains(fn ($e) => !empty($e['nachgereicht']))) {
            $issues[] = 'Offline nachgereichte Einträge sind vorhanden.';
        }

        // Shift and time records are joined through the Einsatz's Objekt, never
        // through a supplied worker or customer ID.
        $shifts = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->where('e.obj_id', $objId)
            ->whereBetween('e.datum', [$start->subDay()->toDateString(), $end->toDateString()])
            ->orderBy('z.clock_in')->get([
                'z.einsatz_id', 'z.dienst_nr', 'z.clock_in', 'z.clock_out', 'z.clock_in_real', 'z.clock_out_real',
                'z.status', 'e.datum as plan_datum', 'e.ist_start', 'e.ist_ende',
            ]);
        $intervals = [];
        $anwesenheit = [];
        $stempelOk = true;
        foreach ($shifts as $z) {
            $rawIn = $z->clock_in_real ?: $z->clock_in;
            $rawOut = $z->clock_out_real ?: $z->clock_out;
            $von = $rawIn ? CarbonImmutable::parse((string) $rawIn, 'UTC') : null;
            $bis = $rawOut ? CarbonImmutable::parse((string) $rawOut, 'UTC') : null;
            if (!$von || ($bis && $bis->lte($start)) || $von->gte($end)) continue;
            $intervals[] = ['von' => $von, 'bis' => $bis];
            if (!$z->clock_in_real || ($z->clock_out && !$z->clock_out_real)) {
                $issues[] = 'Originalzeitstempel fehlt für ' . Qm::workerName($z->dienst_nr) . '.';
                $stempelOk = false;
            }
            if (!$bis) {
                $issues[] = 'Check-out fehlt für ' . Qm::workerName($z->dienst_nr) . '.';
                $stempelOk = false;
            }
            $planVon = $z->ist_start
                ? CarbonImmutable::parse(substr((string) $z->plan_datum, 0, 10) . ' ' . substr((string) $z->ist_start, 0, 5), 'Europe/Berlin') : null;
            $planBis = $planVon && $z->ist_ende
                ? CarbonImmutable::parse(substr((string) $z->plan_datum, 0, 10) . ' ' . substr((string) $z->ist_ende, 0, 5), 'Europe/Berlin') : null;
            if ($planVon && $planBis && $planBis->lte($planVon)) $planBis = $planBis->addDay();
            $anwesenheit[] = [
                'dienst_nr' => $z->dienst_nr, 'name' => Qm::workerName($z->dienst_nr),
                'plan_von' => $planVon?->toIso8601String(), 'plan_bis' => $planBis?->toIso8601String(),
                'check_in' => $von->toIso8601String(), 'check_out' => $bis?->toIso8601String(),
                'dauer_min' => $bis ? max(0, (int) round(($bis->getTimestamp() - $von->getTimestamp()) / 60)) : null,
                'abweichung_in_min' => $planVon ? (int) round(($von->getTimestamp() - $planVon->getTimestamp()) / 60) : null,
                'abweichung_out_min' => $planBis && $bis ? (int) round(($bis->getTimestamp() - $planBis->getTimestamp()) / 60) : null,
                'da' => null,
            ];
        }
        $coverage = WachbuchCoverage::pruefen($start, $end, $intervals);
        $coverage['lueckenlos'] = $coverage['lueckenlos'] && $stempelOk;
        $coverage['originalzeiten_ok'] = $stempelOk;
        if ($cfg->durchgehend_bewacht && !$coverage['lueckenlos']) $issues[] = 'Lückenlose Besetzung nicht nachgewiesen.';
        if ($cfg->durchgehend_bewacht && !$anwesenheit) $issues[] = 'Keine Check-ins für die vereinbarte 24-Stunden-Bewachung.';

        $da = Qm::t('qm_dienstanweisungen')->where('obj_id', $objId)->whereIn('status', ['freigegeben', 'archiv'])
            ->where('gueltig_ab', '<=', $datum)->orderByDesc('version')->first(['id', 'version', 'gueltig_ab']);
        if ($da && $anwesenheit) {
            $bestaetigt = Qm::t('qm_da_bestaetigungen')->where('dienstanweisung_id', $da->id)
                ->whereIn('dienst_nr', array_column($anwesenheit, 'dienst_nr'))->get()->keyBy('dienst_nr');
            foreach ($anwesenheit as &$a) {
                $signed = $bestaetigt[$a['dienst_nr']]->bestaetigt_am ?? null;
                $a['da'] = [
                    'version' => (int) $da->version,
                    'bestaetigt' => $signed && CarbonImmutable::parse((string) $signed, 'UTC')->lte(CarbonImmutable::parse($a['check_in'])),
                ];
                if (!$a['da']['bestaetigt']) $issues[] = 'Dienstanweisung v' . $da->version . ' für ' . $a['name'] . ' vor Dienstbeginn nicht bestätigt.';
            }
            unset($a);
        } elseif ($cfg->bericht_pflicht && !$da) {
            $issues[] = 'Keine gültige Dienstanweisung vorhanden.';
        }

        $besucher = [];
        $abmeldungen = [];
        foreach ($valid->where('art', 'besucher') as $e) {
            $d = (array) $e['details'];
            if (($d['vorgang'] ?? '') === 'abmeldung') $abmeldungen[(int) ($d['anmeldung_id'] ?? 0)] = $e['zeit'];
            else $besucher[] = [
                'id' => $e['id'], 'zeit' => $e['zeit'], 'name' => $d['name'] ?? null, 'ziel' => $d['ziel'] ?? null,
                'angemeldet' => $d['angemeldet'] ?? null, 'ausweis_geprueft' => $d['ausweis_geprueft'] ?? null,
                'ausnahme_grund' => $d['ausnahme_grund'] ?? null,
                'erfasst_am' => $e['erfasst_am'] ?? null, 'gegangen' => null,
            ];
        }
        $besuchsEnde = $cfg->besuchszeit_ende
            ? CarbonImmutable::parse($datum . ' ' . $cfg->besuchszeit_ende, 'Europe/Berlin') : null;
        if ($besuchsEnde && $besuchsEnde->lte($start)) $besuchsEnde = $besuchsEnde->addDay();
        foreach ($besucher as &$b) {
            $b['gegangen'] = $abmeldungen[$b['id']] ?? null;
            if ($cfg->besucher_ausweis_pflicht && $b['ausweis_geprueft'] !== true) $issues[] = 'Ausweiskontrolle bei Besucher #' . $b['id'] . ' nicht dokumentiert.';
            if ($besuchsEnde && CarbonImmutable::parse($b['erfasst_am'] ?: $b['zeit'])->gte($besuchsEnde)) {
                $issues[] = 'Besucher #' . $b['id'] . ' nach Besuchszeit '
                    . ($b['ausnahme_grund'] ? '(Ausnahme dokumentiert)' : '(Ausnahme nicht dokumentiert)') . '.';
            }
            if (!$b['gegangen']) $issues[] = 'Abmeldung von Besucher #' . $b['id'] . ' fehlt.';
        }
        unset($b);
        $lieferungen = $valid->where('art', 'lieferung')->map(function ($e) use ($cfg, &$issues) {
            $d = (array) $e['details'];
            if ($cfg->lieferung_freigabe_pflicht
                && (empty($d['freigabe_bestaetigt']) || empty($d['freigabe_name']) || ($d['freigabe_durch'] ?? 'ohne') === 'ohne')) {
                $issues[] = 'Freigabe für Lieferung #' . $e['id'] . ' nicht nachgewiesen.';
            }
            return ['zeit' => $e['zeit'], 'firma' => $d['firma'] ?? '', 'zweck' => $d['zweck'] ?? '',
                'freigabe_durch' => $d['freigabe_durch'] ?? null, 'freigabe_name' => $d['freigabe_name'] ?? null,
                'freigabe_bestaetigt' => !empty($d['freigabe_bestaetigt'])];
        })->values()->all();

        $keys = Qm::t('qm_wb_schluessel')->where('obj_id', $objId)->where('aktiv', 1)
            ->where('created_at', '<', $end->utc()->toDateTimeString())->orderBy('kennung')->get();
        $bewegungen = $valid->where('art', 'schluessel')->filter(fn ($e) => CarbonImmutable::parse($e['zeit'])->lt($end))->values();
        $unregistriert = $bewegungen->contains(fn ($e) => !$keys->contains('kennung', $e['details']['schluessel'] ?? null));
        $schluessel = [];
        foreach ($keys as $key) {
            $geprueft = $key->bestand_geprueft_am
                && CarbonImmutable::parse((string) $key->bestand_geprueft_am, 'UTC')->lte($start);
            $events = $bewegungen->filter(fn ($e) => ($e['details']['schluessel'] ?? null) === $key->kennung
                && (!$key->bestand_geprueft_am || CarbonImmutable::parse($e['zeit'])->gte(CarbonImmutable::parse((string) $key->bestand_geprueft_am, 'UTC'))));
            $last = $events->last();
            $ausgegeben = $last && ($last['details']['aktion'] ?? null) === 'ausgabe';
            $schluessel[] = [
                'kennung' => $key->kennung, 'bezeichnung' => $key->bezeichnung,
                'bestand_geprueft' => (bool) $geprueft, 'ausgegeben' => (bool) $ausgegeben,
                'an' => $ausgegeben ? ($last['details']['an'] ?? null) : null,
            ];
            if (!$geprueft) $issues[] = 'Bestand von Schlüssel ' . $key->kennung . ' nicht vor Tagesbeginn geprüft.';
        }
        if ($cfg->bericht_pflicht && !$keys->count()) $issues[] = 'Kein Schlüsselbestand für dieses Objekt bestätigt.';
        if ($unregistriert) {
            $issues[] = 'Schlüsselbewegung ohne registrierten Schlüssel vorhanden.';
        }

        $uebergaben = $valid->where('art', 'uebergabe')->filter(fn ($e) => in_array($e['details']['vorgang'] ?? null, ['uebergeben', 'uebernommen'], true));
        if ($cfg->uebergabe_checkliste && count($anwesenheit) > 1) {
            foreach ($uebergaben->where('details.vorgang', 'uebergeben') as $e) {
                if (!$uebergaben->contains(fn ($x) => ($x['details']['vorgang'] ?? null) === 'uebernommen'
                    && (int) ($x['details']['referenz_id'] ?? 0) === $e['id'])) {
                    $issues[] = 'Übergabe #' . $e['id'] . ' wurde nicht übernommen.';
                }
            }
            foreach ($uebergaben as $e) {
                foreach (['schluessel_ok', 'funk_ok', 'objekt_ok'] as $field) {
                    if (($e['details'][$field] ?? null) !== true) $issues[] = 'Abweichung bei ' . $field . ' in Übergabe #' . $e['id'] . '.';
                }
            }
            if ($uebergaben->where('details.vorgang', 'uebergeben')->isEmpty()
                || $uebergaben->where('details.vorgang', 'uebernommen')->isEmpty()) $issues[] = 'Beidseitige Übergabe-Checkliste fehlt.';
        }

        return [
            'obj_id' => $objId, 'objektname' => $o->objektname, 'kundenname' => $o->kundenname,
            'adresse' => trim(($o->adresse ?? '') . ', ' . trim(($o->plz ?? '') . ' ' . ($o->stadt ?? '')), ', '),
            'firma' => $tenant->name ?? '', 'datum' => $datum, 'von' => $start->toIso8601String(),
            'bis' => $end->toIso8601String(), 'deckung' => $coverage,
            'durchgehend_bewacht' => (bool) $cfg->durchgehend_bewacht,
            'besuchszeit_ende' => $cfg->besuchszeit_ende,
            'waechterkontrolle_aktiv' => (bool) $cfg->waechterkontrolle_aktiv,
            'besucher' => $besucher, 'lieferungen' => $lieferungen, 'schluessel' => $schluessel,
            'schluessel_gesamt' => count($schluessel),
            'schluessel_vorhanden' => collect($schluessel)->filter(fn ($k) => $k['bestand_geprueft'] && !$k['ausgegeben'])->count(),
            'schluessel_vollstaendig' => $keys->isNotEmpty() && !$unregistriert
                && collect($schluessel)->every(fn ($k) => $k['bestand_geprueft'] && !$k['ausgegeben']),
            'anwesenheit' => $anwesenheit, 'wachpersonen' => count(array_unique(array_column($anwesenheit, 'dienst_nr'))),
            'dienstanweisung' => $da ? ['version' => (int) $da->version, 'gueltig_ab' => substr((string) $da->gueltig_ab, 0, 10)] : null,
            'vorkommnisse' => $valid->whereIn('art', ['vorkommnis', 'alarm'])->values()->all(),
            'uebergaben' => $uebergaben->values()->all(),
            'eintraege' => $valid->all(), 'pruefung' => array_values(array_unique($issues)),
            'blatt_hashes' => $blaetter->map(fn ($b) => [
                'id' => (int) $b->id, 'letzte_nr' => (int) $b->letzte_nr, 'letzter_hash' => $b->letzter_hash,
            ])->all(),
        ];
    }

    public static function letzter(string $objId, string $datum): ?object
    {
        TenantService::assertActive();
        return Qm::t('qm_wb_berichte')->where('obj_id', $objId)->where('datum', $datum)->orderByDesc('version')->first();
    }

    public static function snapshot(object $row): array
    {
        TenantService::assertActive();
        if (!hash_equals((string) $row->pruefsumme, hash('sha256', (string) $row->daten))) {
            throw new \DomainException('Berichts-Prüfsumme stimmt nicht mit dem gespeicherten Stand überein.');
        }
        $data = json_decode((string) $row->daten, true, 512, JSON_THROW_ON_ERROR);
        if (($data['obj_id'] ?? null) !== $row->obj_id || ($data['datum'] ?? null) !== substr((string) $row->datum, 0, 10)) {
            throw new \DomainException('Berichtsstand gehört nicht zu diesem Objekt und Tag.');
        }
        return $data;
    }

    /** Explicit customer projection. Never serialize the full internal snapshot. */
    public static function kundenSicht(array $d): array
    {
        $fields = ['obj_id', 'objektname', 'kundenname', 'adresse', 'firma', 'datum', 'von', 'bis',
            'deckung', 'durchgehend_bewacht', 'waechterkontrolle_aktiv', 'besucher', 'besuchszeit_ende', 'schluessel_gesamt',
            'schluessel_vorhanden', 'schluessel_vollstaendig', 'wachpersonen'];
        $out = array_intersect_key($d, array_flip($fields));
        if (Qm::boolSetting('portal_dienstanweisungen')) $out['dienstanweisung'] = $d['dienstanweisung'] ?? null;
        $out['besucher'] = collect($d['besucher'] ?? [])->map(fn ($b) =>
            array_intersect_key($b, array_flip(['id', 'zeit', 'name', 'ziel', 'angemeldet', 'gegangen'])))->values()->all();
        $out['lieferungen'] = collect($d['lieferungen'] ?? [])->map(fn ($l) =>
            array_intersect_key($l, array_flip(['zeit', 'firma', 'zweck'])))->values()->all();
        $out['schluessel'] = collect($d['schluessel'] ?? [])->map(fn ($k) =>
            array_intersect_key($k, array_flip(['kennung', 'bezeichnung', 'bestand_geprueft', 'ausgegeben'])))->values()->all();
        $out['eintraege'] = collect($d['eintraege'] ?? [])
            ->whereIn('art', WachbuchPdf::KUNDE_ARTEN)->map(function ($e) {
                $allowed = ['id', 'zeit', 'art', 'art_label', 'ort', 'text', 'wichtig', 'nr',
                    'nachgereicht', 'status', 'erfasst_von', 'berichtigt_durch', 'ref_id'];
                $entry = array_intersect_key($e, array_flip($allowed));
                $entry['details'] = WachbuchPdf::kundenDetails($e['art'], (array) ($e['details'] ?? []));
                $entry['fotos'] = collect($e['fotos'] ?? [])->map(fn ($f) =>
                    array_intersect_key($f, array_flip(['id', 'aufgenommen_am'])))->values()->all();
                return $entry;
            })->values()->all();
        $out['vorkommnisse'] = collect($out['eintraege'])->whereIn('art', ['vorkommnis', 'alarm'])->values()->all();
        $out['pruefung_hinweis'] = !empty($d['pruefung']) ? 'Abweichungen sind im internen Prüfprotokoll dokumentiert.' : null;
        return $out;
    }

    public static function freigeben(string $objId, string $datum, object $user, ?string $ausnahme = null): object
    {
        TenantService::assertActive();
        [$start, $end] = self::grenzen($datum);
        if (CarbonImmutable::now('Europe/Berlin')->lt($end->addHours(12))) {
            throw new \DomainException('Bericht erst zwölf Stunden nach Tagesende freigeben (Offline-Nachreichfrist).');
        }
        return DB::connection('tenant')->transaction(function () use ($objId, $datum, $user, $ausnahme) {
            $obj = Qm::t('objekte')->where('obj_id', $objId)->lockForUpdate()->first(['obj_id']);
            if (!$obj) throw new \DomainException('Objekt nicht gefunden.');
            $daten = self::entwurf($objId, $datum);
            $critical = collect($daten['pruefung'])->filter(fn ($i) => str_contains($i, 'Prüfkette') || str_contains($i, 'noch offen'));
            if ($critical->isNotEmpty()) throw new \DomainException($critical->first());
            if ($daten['pruefung'] && mb_strlen(trim((string) $ausnahme)) < 10) {
                throw new \DomainException('Abweichungen begründen (mindestens 10 Zeichen), bevor der Bericht freigegeben wird.');
            }
            $daten['ausnahme'] = trim((string) $ausnahme) ?: null;
            $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);
            $last = self::letzter($objId, $datum);
            if ($last && hash_equals($last->pruefsumme, $hash)) return $last;
            $version = (int) ($last->version ?? 0) + 1;
            $id = Qm::t('qm_wb_berichte')->insertGetId([
                'obj_id' => $objId, 'datum' => $datum, 'version' => $version, 'daten' => $json,
                'pruefsumme' => $hash, 'freigegeben_von' => $user->id, 'freigegeben_am' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Qm::log('wachbuch_bericht', $id, 'freigegeben', null,
                ['obj_id' => $objId, 'datum' => $datum, 'version' => $version, 'pruefsumme' => $hash], $user);
            return Qm::t('qm_wb_berichte')->find($id);
        });
    }
}
