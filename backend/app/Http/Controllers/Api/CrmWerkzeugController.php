<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Lead-Modul Phase 2 tools (Vertriebsleitung / Admin):
 *   A1 Zusammenführen — merge a duplicate contact or firm into the original.
 *   Import — CSV/Excel of existing contacts with column mapping, duplicate
 *            check (A1, also within the file) and optional lead creation
 *            with the Zuweisungsregel (§6).
 */
class CrmWerkzeugController extends Controller
{
    private const FELDER = [
        'firma' => 'Firma', 'strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort', 'bundesland' => 'Bundesland',
        'firma_telefon' => 'Telefon Firma', 'website' => 'Website', 'branche' => 'Branche',
        'anrede' => 'Anrede', 'vorname' => 'Vorname', 'nachname' => 'Nachname', 'position' => 'Position',
        'email' => 'E-Mail', 'telefon' => 'Telefon', 'mobil' => 'Mobil', 'quelle' => 'Quelle', 'notiz' => 'Notiz',
    ];
    /** Header synonyms for the automatic column mapping (normalized: lowercase, no spaces/punctuation). */
    private const SYNONYME = [
        'firma' => ['firma', 'firmenname', 'unternehmen', 'company', 'organisation', 'kunde', 'kundenname', 'name1'],
        'strasse' => ['strasse', 'straße', 'str', 'street', 'adresse', 'anschrift', 'strassehausnr'],
        'plz' => ['plz', 'postleitzahl', 'zip', 'postalcode'],
        'ort' => ['ort', 'stadt', 'city', 'wohnort'],
        'bundesland' => ['bundesland', 'land', 'state', 'region'],
        'firma_telefon' => ['zentrale', 'firmentelefon', 'telefonzentrale'],
        'website' => ['website', 'webseite', 'homepage', 'web', 'url', 'internet'],
        'branche' => ['branche', 'industry', 'sektor'],
        'anrede' => ['anrede', 'salutation', 'titel'],
        'vorname' => ['vorname', 'firstname', 'givenname'],
        'nachname' => ['nachname', 'name', 'lastname', 'surname', 'familienname', 'ansprechpartner'],
        'position' => ['position', 'funktion', 'jobtitle', 'abteilung', 'rolle'],
        'email' => ['email', 'emailadresse', 'mail', 'e-mail'],
        'telefon' => ['telefon', 'tel', 'phone', 'telefonnummer', 'festnetz', 'telefongeschaeftlich'],
        'mobil' => ['mobil', 'handy', 'mobile', 'mobilnummer', 'mobiltelefon'],
        'quelle' => ['quelle', 'source', 'herkunft'],
        'notiz' => ['notiz', 'notizen', 'bemerkung', 'kommentar', 'notes', 'info'],
    ];

    private function guard(Request $request)
    {
        if (!Crm::ready()) return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet'], 409);
        $u = $request->user();
        if (!Crm::can($u, 'all') && !Crm::can($u, 'admin')) {
            return response()->json(['error' => 'Nur für Vertriebsleitung / Geschäftsleitung'], 403);
        }
        return null;
    }

    // =============================================================
    // A1 — Zusammenführen
    // =============================================================

    /** POST /crm/kontakte/{id}/zusammenfuehren {quelle_id} — merge quelle INTO {id}. */
    public function kontakteZusammenfuehren(Request $request, string $id)
    {
        if ($r = $this->guard($request)) return $r;
        $u = $request->user();
        $qid = (string) $request->input('quelle_id');
        $ziel = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->first();
        $quelle = Crm::t('crm_kontakte')->where('id', $qid)->whereNull('deleted_at')->first();
        if (!$ziel || !$quelle) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        if ($ziel->id === $quelle->id) return Crm::fehler(['quelle_id' => ['Ein Kontakt kann nicht mit sich selbst zusammengeführt werden.']]);

        $ergebnis = DB::connection('tenant')->transaction(function () use ($u, $ziel, $quelle) {
            // Empty fields of the original are filled from the duplicate.
            $fill = [];
            foreach (['anrede', 'vorname', 'position', 'email', 'telefon', 'mobil', 'firma_id', 'telefon_norm', 'mobil_norm'] as $f) {
                if (($ziel->$f ?? null) === null || $ziel->$f === '') if (($quelle->$f ?? null) !== null && $quelle->$f !== '') $fill[$f] = $quelle->$f;
            }
            if (!$ziel->entscheider && $quelle->entscheider) $fill['entscheider'] = 1;
            if (!$ziel->dsgvo_einwilligung && $quelle->dsgvo_einwilligung) { $fill['dsgvo_einwilligung'] = 1; $fill['dsgvo_datum'] = $quelle->dsgvo_datum; }
            if ($fill) Crm::t('crm_kontakte')->where('id', $ziel->id)->update($fill + ['updated_at' => now()]);

            // Leads: one open lead per Sparte — a second open one becomes "Dublette".
            $disq = 0;
            foreach (Crm::t('crm_leads')->where('kontakt_id', $quelle->id)->whereNull('deleted_at')->get() as $l) {
                $orig = in_array($l->stage, Crm::LEAD_OFFEN, true)
                    ? Crm::t('crm_leads')->where('kontakt_id', $ziel->id)->whereNull('deleted_at')->whereIn('stage', Crm::LEAD_OFFEN)
                        ->whereRaw('LOWER(COALESCE(sparte, \'\')) = ?', [mb_strtolower((string) $l->sparte)])->value('id')
                    : null;
                Crm::t('crm_leads')->where('id', $l->id)->update(['kontakt_id' => $ziel->id, 'firma_id' => $l->firma_id ?: ($fill['firma_id'] ?? $ziel->firma_id)]);
                if ($orig) {
                    Crm::setLeadStage($l, 'disqualifiziert', $u, null, ['disq_grund' => 'dublette', 'duplikat_von' => $orig,
                        'disq_notiz' => 'Beim Zusammenführen der Kontakte (A1) als Dublette geschlossen.']);
                    $disq++;
                }
            }
            Crm::t('crm_deals')->where('kontakt_id', $quelle->id)->update(['kontakt_id' => $ziel->id]);
            Crm::t('crm_aktivitaeten')->where('kontakt_id', $quelle->id)->update(['kontakt_id' => $ziel->id]);
            Crm::t('aufgaben')->where('bezug_typ', 'kontakt')->where('bezug_id', $quelle->id)->update(['bezug_id' => $ziel->id]);

            // Lifecycle: the further stage wins (forward only).
            Crm::setLifecycle($ziel->id, $quelle->lifecycle_stage, $u, 'A1');
            Crm::t('crm_kontakte')->where('id', $quelle->id)->update(['merged_into' => $ziel->id, 'deleted_at' => now(), 'updated_at' => now()]);
            Crm::log('kontakt', $quelle->id, 'merged_into', null, $ziel->id, $u, 'A1');
            Crm::log('kontakt', $ziel->id, 'zusammengefuehrt', null, $quelle->id, $u, 'A1');
            return ['felder' => array_keys($fill), 'leads_dublette' => $disq];
        });

        Crm::changed();
        return response()->json(['ok' => true, 'id' => $ziel->id] + $ergebnis);
    }

    /** POST /crm/firmen/{id}/zusammenfuehren {quelle_id} — merge firm quelle INTO {id}. */
    public function firmenZusammenfuehren(Request $request, string $id)
    {
        if ($r = $this->guard($request)) return $r;
        $u = $request->user();
        $qid = (string) $request->input('quelle_id');
        $ziel = Crm::t('crm_firmen')->where('id', $id)->whereNull('deleted_at')->first();
        $quelle = Crm::t('crm_firmen')->where('id', $qid)->whereNull('deleted_at')->first();
        if (!$ziel || !$quelle) return response()->json(['error' => 'Firma nicht gefunden'], 404);
        if ($ziel->id === $quelle->id) return Crm::fehler(['quelle_id' => ['Eine Firma kann nicht mit sich selbst zusammengeführt werden.']]);
        if ($ziel->kunde_id && $quelle->kunde_id && $ziel->kunde_id !== $quelle->kunde_id) {
            return Crm::fehler(['quelle_id' => ["Beide Firmen sind mit verschiedenen Kunden verknüpft ({$ziel->kunde_id} / {$quelle->kunde_id}) — bitte zuerst im Kundenmodul klären."]]);
        }

        DB::connection('tenant')->transaction(function () use ($u, $ziel, $quelle) {
            $fill = [];
            foreach (['strasse', 'plz', 'ort', 'bundesland', 'branche', 'mitarbeiterzahl', 'telefon', 'website', 'kunde_id'] as $f) {
                if (($ziel->$f ?? null) === null || $ziel->$f === '') if (($quelle->$f ?? null) !== null && $quelle->$f !== '') $fill[$f] = $quelle->$f;
            }
            if ($fill) Crm::t('crm_firmen')->where('id', $ziel->id)->update($fill + ['updated_at' => now()]);
            foreach (['crm_kontakte', 'crm_leads', 'crm_deals'] as $tb) {
                Crm::t($tb)->where('firma_id', $quelle->id)->update(['firma_id' => $ziel->id]);
            }
            Crm::t('crm_firmen')->where('id', $quelle->id)->update(['merged_into' => $ziel->id, 'deleted_at' => now(), 'updated_at' => now()]);
            Crm::log('firma', $quelle->id, 'merged_into', null, $ziel->id, $u, 'A1');
            Crm::log('firma', $ziel->id, 'zusammengefuehrt', null, $quelle->id, $u, 'A1');
        });

        Crm::changed();
        return response()->json(['ok' => true, 'id' => $ziel->id]);
    }

    // =============================================================
    // DSGVO — data export (Art. 15/20): everything we store about a contact
    // =============================================================

    /** GET /crm/kontakte/{id}/dsgvo-export → JSON download, logged in crm_protokoll. */
    public function kontaktExport(Request $request, string $id)
    {
        if ($r = $this->guard($request)) return $r;
        $u = $request->user();
        $k = Crm::t('crm_kontakte')->where('id', $id)->first();
        if (!$k || $k->merged_into) $k = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$k) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);

        $leadIds = Crm::t('crm_leads')->where('kontakt_id', $id)->pluck('id');
        $dealIds = Crm::t('crm_deals')->where('kontakt_id', $id)->pluck('id');
        $export = [
            'exportiert_am' => now()->toIso8601String(), 'exportiert_von' => Crm::userName($u),
            'grund' => 'DSGVO Auskunft (Art. 15/20)',
            'kontakt' => $k,
            'firma' => $k->firma_id ? Crm::t('crm_firmen')->where('id', $k->firma_id)->first() : null,
            'leads' => Crm::t('crm_leads')->where('kontakt_id', $id)->get()->map(function ($l) {
                $l->checkliste = json_decode((string) $l->checkliste, true); return $l;
            }),
            'deals' => Crm::t('crm_deals')->where('kontakt_id', $id)->get()->map(function ($d) {
                unset($d->angebot_pfad); return $d; // file path stays internal; the file itself is listed by name
            }),
            'aktivitaeten' => Crm::t('crm_aktivitaeten')->where('kontakt_id', $id)->orderBy('zeitpunkt')->get()
                ->map(fn ($a) => collect($a)->except('datei_disk', 'datei_pfad')),
            'aufgaben' => Crm::t('aufgaben')->where(fn ($w) => $w
                    ->where(fn ($x) => $x->where('bezug_typ', 'kontakt')->where('bezug_id', $id))
                    ->orWhere(fn ($x) => $x->where('bezug_typ', 'lead')->whereIn('bezug_id', $leadIds))
                    ->orWhere(fn ($x) => $x->where('bezug_typ', 'deal')->whereIn('bezug_id', $dealIds)))
                ->orderBy('faellig_am')->get(),
            'protokoll' => Crm::t('crm_protokoll')
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('objekt', 'kontakt')->where('objekt_id', $id))
                    ->orWhere(fn ($x) => $x->where('objekt', 'lead')->whereIn('objekt_id', $leadIds))
                    ->orWhere(fn ($x) => $x->where('objekt', 'deal')->whereIn('objekt_id', $dealIds)))
                ->orderBy('created_at')->get(),
        ];
        Crm::log('kontakt', $id, 'dsgvo_export', null, Crm::heute(), $u, 'DSGVO');
        return response(json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="dsgvo-export-' . preg_replace('/[^a-z0-9]+/i', '-', trim(($k->vorname ?? '') . '-' . $k->nachname)) . '.json"',
        ]);
    }

    // =============================================================
    // Import
    // =============================================================

    private static function norm(string $h): string
    {
        return preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower(trim($h)));
    }

    /** Rows of a CSV/XLSX/XLS file as arrays of strings (first row = header). */
    private function lesen(string $pfad, int $max): array
    {
        $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            $reader = new Csv();
            $reader->setInputEncoding(Csv::guessEncoding($pfad, 'Windows-1252'));
        } else {
            $reader = IOFactory::createReaderForFile($pfad);
        }
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($pfad)->getActiveSheet();
        $rows = [];
        foreach ($sheet->getRowIterator() as $i => $row) {
            if (count($rows) > $max + 1) break;
            $cells = [];
            foreach ($row->getCellIterator() as $cell) $cells[] = trim((string) $cell->getFormattedValue());
            if (implode('', $cells) === '') continue;
            $rows[] = $cells;
        }
        return $rows;
    }

    /** POST /crm/import/vorschau (multipart datei) → token, header, sample rows, suggested mapping. */
    public function importVorschau(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        [, $err] = Crm::pruefe($request->all(), ['datei' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls']);
        if ($err) return Crm::fehler($err);
        $file = $request->file('datei');
        $ext = strtolower($file->getClientOriginalExtension()) ?: 'csv';
        $tenant = \App\Services\TenantService::current();
        $token = Str::random(32);
        $pfad = "crm/{$tenant->id}/import/{$token}.{$ext}";
        $file->storeAs(dirname($pfad), basename($pfad), 'local');

        $max = Crm::intSetting('import_max_zeilen', 2000);
        try {
            $rows = $this->lesen(Storage::disk('local')->path($pfad), $max);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($pfad);
            return Crm::fehler(['datei' => ['Datei konnte nicht gelesen werden: ' . $e->getMessage()]]);
        }
        if (count($rows) < 2) {
            Storage::disk('local')->delete($pfad);
            return Crm::fehler(['datei' => ['Die Datei enthält keine Datenzeilen (erste Zeile = Spaltenüberschriften).']]);
        }
        $header = array_shift($rows);

        // Suggest: exact synonym first, then "header starts with synonym" (long synonyms only).
        $vorschlag = [];
        $belegt = [];
        foreach ([true, false] as $exakt) {
            foreach (self::SYNONYME as $feld => $syn) {
                if (isset($vorschlag[$feld])) continue;
                foreach ($header as $i => $h) {
                    if (isset($belegt[$i])) continue;
                    $n = self::norm($h);
                    $treffer = $exakt ? in_array($n, array_map([self::class, 'norm'], $syn), true)
                        : (bool) collect($syn)->first(fn ($s) => mb_strlen($s) >= 4 && str_starts_with($n, self::norm($s)));
                    if ($treffer) { $vorschlag[$feld] = $i; $belegt[$i] = true; break; }
                }
            }
        }
        return response()->json([
            'token' => $token . '.' . $ext, 'datei_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'header' => $header, 'beispiel' => array_slice($rows, 0, 8), 'zeilen' => count($rows),
            'max_zeilen' => $max, 'zu_viele' => count($rows) > $max,
            'felder' => self::FELDER, 'vorschlag' => (object) $vorschlag,
        ]);
    }

    /**
     * POST /crm/import/ausfuehren {token, datei_name, mapping{feld: spalte}, dubletten: ueberspringen|anlegen,
     *   quelle, lead:{anlegen, sparte, prioritaet}, owner: auto|ich|<id>}
     */
    public function importAusfuehren(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        $u = $request->user();
        [$d, $err] = Crm::pruefe($request->all(), [
            'token' => ['required', 'regex:/^[A-Za-z0-9]{32}\.(csv|txt|xlsx|xls)$/'], 'datei_name' => 'nullable|string|max:255',
            'mapping' => 'required|array', 'mapping.*' => 'nullable|integer|min:0|max:500',
            'dubletten' => 'required|in:ueberspringen,anlegen', 'quelle' => 'nullable|string|max:60',
            'lead' => 'nullable|array', 'lead.anlegen' => 'nullable|boolean', 'lead.sparte' => 'nullable|string|max:190',
            'lead.prioritaet' => 'nullable|string|max:60', 'owner' => 'nullable|string|max:20',
        ]);
        if ($err) return Crm::fehler($err);
        $tenant = \App\Services\TenantService::current();
        $pfad = "crm/{$tenant->id}/import/{$d['token']}";
        if (!Storage::disk('local')->exists($pfad)) return Crm::fehler(['token' => ['Import-Datei nicht mehr vorhanden — bitte erneut hochladen.']], 410);

        $map = array_filter($d['mapping'], fn ($v) => $v !== null && $v !== '');
        $map = array_intersect_key($map, self::FELDER);
        if (!isset($map['nachname'])) return Crm::fehler(['mapping' => ['Die Spalte „Nachname“ muss zugeordnet werden.']]);
        $lead = !empty($d['lead']['anlegen']) ? $d['lead'] : null;
        if ($lead && !Crm::sparteGueltig($u, $lead['sparte'] ?? null)) return Crm::fehler(['lead.sparte' => ['Für die Lead-Anlage eine Sparte wählen.']]);
        $quelleStd = Crm::gueltig('quelle', $d['quelle'] ?? 'import') ? ($d['quelle'] ?? 'import') : 'import';
        $ownerWahl = $d['owner'] ?? 'auto';
        if (ctype_digit((string) $ownerWahl) && !Crm::ownerGueltig($u, $ownerWahl)) return Crm::fehler(['owner' => ['Ungültiger Owner.']]);

        @set_time_limit(600);
        $max = Crm::intSetting('import_max_zeilen', 2000);
        $rows = $this->lesen(Storage::disk('local')->path($pfad), $max);
        array_shift($rows);
        $rows = array_slice($rows, 0, $max);

        $quellen = Crm::liste('quelle', true)->mapWithKeys(fn ($q, $k) => [self::norm($q->name) => $k, self::norm($k) => $k])->all();
        $branchen = Crm::liste('branche', true)->mapWithKeys(fn ($q, $k) => [self::norm($q->name) => $k, self::norm($k) => $k])->all();
        $stamm = app(CrmStammController::class);
        $firmaCache = [];
        $gesehen = [];
        $st = ['angelegt' => 0, 'dubletten' => 0, 'fehler' => 0, 'leads' => 0, 'firmen_neu' => 0];
        $fehlerListe = [];
        $proOwner = [];

        foreach ($rows as $nr => $cells) {
            $zeile = $nr + 2; // 1-based + header
            $v = [];
            foreach ($map as $feld => $col) $v[$feld] = trim((string) ($cells[$col] ?? ''));
            if (isset($v['plz']) && preg_match('/^\d{4}$/', $v['plz'])) $v['plz'] = '0' . $v['plz']; // Excel dropped the leading zero
            $fehler = function (string $msg) use (&$st, &$fehlerListe, $zeile) {
                $st['fehler']++;
                if (count($fehlerListe) < 300) $fehlerListe[] = ['zeile' => $zeile, 'grund' => $msg];
            };

            [$ok, $verr] = Crm::pruefe($v, [
                'nachname' => 'required|max:100', 'vorname' => 'nullable|max:100', 'anrede' => 'nullable|max:20', 'position' => 'nullable|max:150',
                'email' => 'nullable|email|max:200', 'telefon' => 'nullable|max:60', 'mobil' => 'nullable|max:60',
                'firma' => 'nullable|max:200', 'strasse' => 'nullable|max:200', 'plz' => 'nullable|max:10', 'ort' => 'nullable|max:120',
                'bundesland' => 'nullable|max:60', 'firma_telefon' => 'nullable|max:60', 'website' => 'nullable|max:200', 'notiz' => 'nullable|max:5000',
            ]);
            if ($verr) { $fehler(collect($verr)->flatten()->implode(' ')); continue; }
            if (($v['email'] ?? '') === '' && ($v['telefon'] ?? '') === '' && ($v['mobil'] ?? '') === '') { $fehler('Weder E-Mail noch Telefon/Mobil.'); continue; }

            // A1 — against the database and within the file.
            $keys = array_filter([mb_strtolower($v['email'] ?? ''), Crm::telNorm($v['telefon'] ?? null), Crm::telNorm($v['mobil'] ?? null)]);
            $inDatei = (bool) array_intersect($keys, array_keys($gesehen));
            $inDb = !$inDatei && Crm::dubletten(['email' => $v['email'] ?? '', 'telefon' => $v['telefon'] ?? '', 'mobil' => $v['mobil'] ?? '']);
            foreach ($keys as $k) $gesehen[$k] = true;
            if (($inDatei || $inDb) && $d['dubletten'] === 'ueberspringen') { $st['dubletten']++; continue; }

            try {
                DB::connection('tenant')->transaction(function () use ($u, $v, $lead, $quelleStd, $quellen, $branchen, $stamm, $ownerWahl, &$firmaCache, &$st, &$proOwner) {
                    // Owner: explicit person, me, or the Sparte's rule (only meaningful with a lead).
                    if (ctype_digit((string) $ownerWahl)) [$owner, $regel] = [(int) $ownerWahl, 'manuell'];
                    elseif ($ownerWahl === 'ich' || !$lead) [$owner, $regel] = [(int) $u->id, 'manuell'];
                    else [$owner, $regel] = Crm::zuweisen($lead['sparte'], $v['plz'] ?? null, (int) $u->tenant_id, (int) $u->id);

                    $firmaId = null;
                    if (($v['firma'] ?? '') !== '') {
                        $fk = mb_strtolower($v['firma']) . '|' . ($v['plz'] ?? '');
                        $firmaId = $firmaCache[$fk] ?? Crm::t('crm_firmen')->whereNull('deleted_at')->where('name', $v['firma'])
                            ->when(($v['plz'] ?? '') !== '', fn ($q) => $q->where('plz', $v['plz']))->value('id');
                        if (!$firmaId) {
                            $firmaId = (string) Str::uuid();
                            Crm::t('crm_firmen')->insert([
                                'id' => $firmaId, 'name' => $v['firma'], 'strasse' => ($v['strasse'] ?? '') ?: null, 'plz' => ($v['plz'] ?? '') ?: null,
                                'ort' => ($v['ort'] ?? '') ?: null, 'bundesland' => ($v['bundesland'] ?? '') ?: null,
                                'branche' => $branchen[self::norm($v['branche'] ?? '')] ?? null, 'telefon' => ($v['firma_telefon'] ?? '') ?: null,
                                'website' => ($v['website'] ?? '') ?: null, 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $st['firmen_neu']++;
                        }
                        $firmaCache[$fk] = $firmaId;
                    }
                    $kid = (string) Str::uuid();
                    Crm::t('crm_kontakte')->insert([
                        'id' => $kid, 'firma_id' => $firmaId, 'anrede' => ($v['anrede'] ?? '') ?: null, 'vorname' => ($v['vorname'] ?? '') ?: null,
                        'nachname' => $v['nachname'], 'position' => ($v['position'] ?? '') ?: null, 'email' => ($v['email'] ?? '') ?: null,
                        'telefon' => ($v['telefon'] ?? '') ?: null, 'mobil' => ($v['mobil'] ?? '') ?: null,
                        'telefon_norm' => Crm::telNorm($v['telefon'] ?? null), 'mobil_norm' => Crm::telNorm($v['mobil'] ?? null),
                        'lifecycle_stage' => 'interessent', 'lifecycle_changed_at' => now(),
                        'quelle' => $quellen[self::norm($v['quelle'] ?? '')] ?? $quelleStd,
                        'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    Crm::log('kontakt', $kid, 'lifecycle_stage', null, 'interessent', $u, 'IMP');
                    if (($v['notiz'] ?? '') !== '') {
                        Crm::t('crm_aktivitaeten')->insert(['kontakt_id' => $kid, 'typ' => 'notiz', 'zeitpunkt' => now(), 'user_id' => $u->id,
                            'notiz' => 'Import: ' . $v['notiz'], 'created_at' => now(), 'updated_at' => now()]);
                    }
                    if ($lead) {
                        $stamm->leadAnlegen($u, $kid, $firmaId, $lead['sparte'], $lead['prioritaet'] ?? 'normal', $owner, true, $regel, false);
                        $st['leads']++;
                        $proOwner[$owner] = ($proOwner[$owner] ?? 0) + 1;
                    }
                    $st['angelegt']++;
                });
            } catch (\Throwable $e) {
                $fehler('Speichern fehlgeschlagen: ' . mb_substr($e->getMessage(), 0, 150));
            }
        }

        Storage::disk('local')->delete($pfad);
        // One summary notification per assignee instead of one per lead.
        foreach ($proOwner as $owner => $n) {
            if ((int) $owner === (int) $u->id) continue;
            NotificationDispatcher::notifyUserIds((int) $tenant->id, [(int) $owner], 'vertrieb.hinweis',
                "$n neue Leads aus Import", Crm::userName($u) . ' hat Ihnen ' . $n . ' Leads zugewiesen (Aufgabe „Erstkontakt“ je Lead).', '#interessenten');
        }
        Crm::t('crm_importe')->insert([
            'user_id' => $u->id, 'datei_name' => $d['datei_name'] ?? $d['token'], 'zeilen' => count($rows),
            'angelegt' => $st['angelegt'], 'dubletten' => $st['dubletten'], 'fehler' => $st['fehler'], 'leads' => $st['leads'],
            'details' => json_encode(['fehler' => $fehlerListe, 'firmen_neu' => $st['firmen_neu'], 'mapping' => $map]), 'created_at' => now(),
        ]);
        Crm::changed();
        return response()->json($st + ['zeilen' => count($rows), 'fehler_liste' => $fehlerListe]);
    }
}
