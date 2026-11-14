<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checklisten engine — shared by every industry.
 *
 * Template field (`felder[]`):
 *   {id, typ, label, pflicht, hinweis, optionen[], einheit, foto}
 *   typ   abschnitt | check | ok_nok | bewertung | text | zahl | auswahl | foto | zeit
 *   foto  nie | optional | bei_mangel | pflicht
 * Answer (`antworten[feld_id]`): {wert, notiz}
 *   check → bool · ok_nok → io|nio|na · bewertung → 1..5 · text → string ·
 *   zahl → number · auswahl → one of optionen · zeit → HH:MM · foto → null
 * Score: ok_nok io = 1 / nio = 0 (na skipped), bewertung (n-1)/4, check 1/0.
 * Mängel: ok_nok nio + bewertung ≤ 2 (both need a note).
 */
class Checklisten
{
    public const KATEGORIEN = [
        'qualitaet'         => 'Qualitätskontrolle',
        'leistungsnachweis' => 'Leistungsnachweis',
        'wartung'           => 'Wartung & Prüfung',
        'winterdienst'      => 'Winterdienst',
        'rundgang'          => 'Kontrollgang',
        'uebergabe'         => 'Übergabe',
        'sonstiges'         => 'Sonstiges',
    ];
    public const TYPEN = ['abschnitt', 'check', 'ok_nok', 'bewertung', 'text', 'zahl', 'auswahl', 'foto', 'zeit'];
    public const FOTO = ['nie', 'optional', 'bei_mangel', 'pflicht'];
    public const UNTERSCHRIFT = ['keine', 'kunde', 'mitarbeiter', 'beide'];
    public const MAX_FELDER = 150;
    public const MAX_FOTOS_JE_FELD = 6;

    public static function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('checklisten_vorlagen');
    }

    private static function str($v, int $max): string
    {
        return mb_substr(trim(strip_tags((string) ($v ?? ''))), 0, $max);
    }

    /** Normalised field list, or an error message. */
    public static function cleanFelder($raw): array|string
    {
        if (!is_array($raw)) return 'Felder fehlen';
        if (count($raw) > self::MAX_FELDER) return 'Zu viele Felder (max. ' . self::MAX_FELDER . ')';
        $out = [];
        $ids = [];
        foreach (array_values($raw) as $i => $f) {
            if (!is_array($f)) continue;
            $typ = (string) ($f['typ'] ?? '');
            if (!in_array($typ, self::TYPEN, true)) return 'Ungültiger Feldtyp in Zeile ' . ($i + 1);
            $label = self::str($f['label'] ?? '', 300);
            if ($label === '') return 'Bezeichnung fehlt in Zeile ' . ($i + 1);
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($f['id'] ?? '')) ?: ('f' . ($i + 1));
            $id = substr($id, 0, 40);
            while (isset($ids[$id])) $id = substr($id, 0, 34) . '_' . bin2hex(random_bytes(2));
            $ids[$id] = true;
            $feld = ['id' => $id, 'typ' => $typ, 'label' => $label];
            if ($typ !== 'abschnitt') {
                $feld['pflicht'] = !empty($f['pflicht']);
                $hint = self::str($f['hinweis'] ?? '', 500);
                if ($hint !== '') $feld['hinweis'] = $hint;
                $foto = (string) ($f['foto'] ?? ($typ === 'foto' ? 'pflicht' : 'nie'));
                if (!in_array($foto, self::FOTO, true)) $foto = 'nie';
                if ($foto === 'bei_mangel' && !in_array($typ, ['ok_nok', 'bewertung'], true)) $foto = 'optional';
                if ($typ === 'foto' && $foto === 'nie') $foto = 'optional';
                $feld['foto'] = $foto;
            }
            if ($typ === 'auswahl') {
                $opts = [];
                foreach ((array) ($f['optionen'] ?? []) as $o) {
                    $o = self::str($o, 120);
                    if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o;
                }
                if (count($opts) < 2) return 'Auswahl „' . $label . '“ braucht mindestens 2 Optionen';
                $feld['optionen'] = array_slice($opts, 0, 30);
            }
            if ($typ === 'zahl') {
                $e = self::str($f['einheit'] ?? '', 20);
                if ($e !== '') $feld['einheit'] = $e;
            }
            $out[] = $feld;
        }
        if (!array_filter($out, fn ($f) => $f['typ'] !== 'abschnitt')) return 'Mindestens ein Feld ist erforderlich';
        return $out;
    }

    /**
     * Validates answers against the template. $fotoCount[feld_id] = number of
     * photos sent for that field. Returns [antworten, prozent|null, maengel]
     * or an error message.
     */
    public static function cleanAntworten(array $felder, $raw, array $fotoCount = []): array|string
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($felder as $f) {
            if ($f['typ'] === 'abschnitt') continue;
            $a = $raw[$f['id']] ?? null;
            $a = is_array($a) ? $a : ['wert' => $a];
            $w = $a['wert'] ?? null;
            $notiz = self::str($a['notiz'] ?? '', 2000);
            $label = '„' . $f['label'] . '“';
            switch ($f['typ']) {
                case 'check':
                    $w = filter_var($w, FILTER_VALIDATE_BOOLEAN);
                    if (!empty($f['pflicht']) && !$w) return "$label muss erledigt sein";
                    break;
                case 'ok_nok':
                    $w = in_array($w, ['io', 'nio', 'na'], true) ? $w : null;
                    if ($w === 'nio' && $notiz === '') return "$label: bitte den Mangel beschreiben";
                    break;
                case 'bewertung':
                    $w = is_numeric($w) && (int) $w >= 1 && (int) $w <= 5 ? (int) $w : null;
                    if ($w !== null && $w <= 2 && $notiz === '') return "$label: bitte die Bewertung begründen";
                    break;
                case 'zahl':
                    $w = is_numeric(str_replace(',', '.', (string) $w)) ? round((float) str_replace(',', '.', (string) $w), 3) : null;
                    break;
                case 'auswahl':
                    $w = in_array($w, $f['optionen'] ?? [], true) ? $w : null;
                    break;
                case 'zeit':
                    $w = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $w) ? (string) $w : null;
                    break;
                case 'text':
                    $w = self::str($w, 5000);
                    if ($w === '') $w = null;
                    break;
                case 'foto':
                    $w = null;
                    break;
            }
            $n = (int) ($fotoCount[$f['id']] ?? 0);
            if (!empty($f['pflicht']) && $f['typ'] !== 'check' && $f['typ'] !== 'foto' && ($w === null || $w === '')) {
                return "$label ist ein Pflichtfeld";
            }
            $foto = $f['foto'] ?? 'nie';
            if ($foto === 'pflicht' && $n < 1) return "$label: Foto erforderlich";
            if ($foto === 'bei_mangel' && $n < 1 && ($w === 'nio' || (is_int($w) && $f['typ'] === 'bewertung' && $w <= 2))) {
                return "$label: bitte den Mangel fotografieren";
            }
            $out[$f['id']] = ['wert' => $w, 'notiz' => $notiz !== '' ? $notiz : null];
        }
        [$prozent, $maengel] = self::auswertung($felder, $out);
        return [$out, $prozent, $maengel];
    }

    /** [prozent|null, maengel] of answered fields. */
    public static function auswertung(array $felder, array $antworten): array
    {
        $sum = 0.0;
        $n = 0;
        $maengel = 0;
        foreach ($felder as $f) {
            $w = $antworten[$f['id']]['wert'] ?? null;
            switch ($f['typ']) {
                case 'ok_nok':
                    if ($w === 'io' || $w === 'nio') { $n++; $sum += $w === 'io' ? 1 : 0; }
                    if ($w === 'nio') $maengel++;
                    break;
                case 'bewertung':
                    if (is_int($w)) { $n++; $sum += ($w - 1) / 4; if ($w <= 2) $maengel++; }
                    break;
                case 'check':
                    $n++; $sum += $w ? 1 : 0;
                    break;
            }
        }
        return [$n ? round($sum / $n * 100, 2) : null, $maengel];
    }

    /** Lowercased Bereich names of an Einsatz: Artikel → Objekt tags (+ its Artikel) → Kunde. */
    public static function einsatzBereiche(?string $objId, ?string $artikelId, ?string $kundeId = null): array
    {
        $db = DB::connection('tenant');
        $tags = [];
        $add = function ($v) use (&$tags) {
            foreach (explode(',', (string) $v) as $p) {
                $p = mb_strtolower(trim($p));
                if ($p !== '') $tags[$p] = true;
            }
        };
        if ($artikelId) $add($db->table('artikel')->where('artikel_id', $artikelId)->value('bereich'));
        if ($objId) {
            $o = $db->table('objekte')->where('obj_id', $objId)->first();
            if ($o) {
                $add($o->bereich ?? '');
                foreach (json_decode($o->bereiche ?? '[]', true) ?: [] as $b) $add($b);
            }
            foreach ($db->table('artikel')->where('obj_id', $objId)->whereNotNull('bereich')->pluck('bereich') as $b) $add($b);
        }
        if (!$tags && $kundeId) $add($db->table('kunden')->where('kunde_id', $kundeId)->value('bereich'));
        return array_keys($tags);
    }

    /**
     * Active templates for an Objekt (+ the Einsatz's Bereiche), each with
     * `pflicht`. Explicit assignment wins over "alle Objekte des Bereichs".
     */
    public static function vorlagenFuer(string $objId, array $bereiche): array
    {
        if (!self::ready()) return [];
        $db = DB::connection('tenant');
        $assigned = $db->table('checklisten_zuordnungen')->where('obj_id', $objId)->pluck('pflicht', 'vorlage_id')->all();
        $mains = $db->table('bereiche_katalog')->whereNull('parent_id')->pluck('name', 'id')
            ->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $out = [];
        foreach ($db->table('checklisten_vorlagen')->where('aktiv', 1)->orderBy('kategorie')->orderBy('name')->get() as $v) {
            $isAssigned = array_key_exists($v->id, $assigned);
            $byBereich = $v->alle_objekte && ($v->bereich_id === null || in_array($mains[$v->bereich_id] ?? '#', $bereiche, true));
            if (!$isAssigned && !$byBereich) continue;
            $v->pflicht = $isAssigned ? (int) $assigned[$v->id] : 0;
            $out[] = $v;
        }
        return $out;
    }

    public static function formatVorlage($v, bool $felder = true): array
    {
        $r = [
            'id' => (int) $v->id, 'name' => $v->name, 'beschreibung' => $v->beschreibung,
            'kategorie' => $v->kategorie, 'kategorie_label' => self::KATEGORIEN[$v->kategorie] ?? $v->kategorie,
            'bereich_id' => $v->bereich_id !== null ? (int) $v->bereich_id : null,
            'alle_objekte' => (bool) $v->alle_objekte, 'unterschrift' => $v->unterschrift,
            'gps' => (bool) $v->gps, 'im_kundenportal' => (bool) $v->im_kundenportal,
            'aktiv' => (bool) $v->aktiv, 'version' => (int) $v->version, 'vorlage_key' => $v->vorlage_key,
            'updated_at' => $v->updated_at,
        ];
        if (isset($v->pflicht)) $r['pflicht'] = (bool) $v->pflicht;
        if ($felder) $r['felder'] = json_decode($v->felder, true) ?: [];
        return $r;
    }

    // ------------------------------------------------------------------
    // Files (private `local` disk, served only through authorised endpoints).

    public static function dir(int $protokollId): string
    {
        $tenant = \App\Services\TenantService::current();
        return 'checklisten/' . (int) ($tenant->id ?? 0) . '/' . $protokollId;
    }

    /**
     * Stores an uploaded photo re-encoded as JPEG (max 1600 px, EXIF/GPS
     * metadata stripped by the re-encode). Returns the path or null.
     */
    public static function storeFoto($file, int $protokollId): ?string
    {
        if (!$file || !$file->isValid() || $file->getSize() > 15 * 1024 * 1024) return null;
        $info = @getimagesize($file->getRealPath());
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
        $bin = self::reencode($file->getRealPath(), $info, 1600);
        if ($bin === null) return null;
        $path = self::dir($protokollId) . '/foto_' . bin2hex(random_bytes(12)) . '.jpg';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bin);
        return $path;
    }

    /** Signature from a data:image/png;base64 URL (canvas). Returns the path or null. */
    public static function storeSignatur(?string $dataUrl, int $protokollId, string $wer): ?string
    {
        if (!$dataUrl || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > 1024 * 1024) return null;
        $img = @imagecreatefromstring($bin);
        if (!$img) return null;
        imagesavealpha($img, true);
        ob_start();
        imagepng($img, null, 9);
        $out = ob_get_clean();
        imagedestroy($img);
        $path = self::dir($protokollId) . '/unterschrift_' . $wer . '_' . bin2hex(random_bytes(8)) . '.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $out);
        return $path;
    }

    private static function reencode(string $src, array $info, int $max): ?string
    {
        if (!function_exists('imagecreatefromstring')) return file_get_contents($src) ?: null;
        $img = @imagecreatefromstring(file_get_contents($src));
        if (!$img) return null;
        // Phone cameras store portrait shots rotated + an EXIF flag.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }
        }
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        if ($scale < 1) {
            $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
        ob_start();
        imagejpeg($img, null, 82);
        $bin = ob_get_clean();
        imagedestroy($img);
        return $bin ?: null;
    }

    /** Deletes a protocol's files + photo rows (protocol row is deleted by the caller). */
    public static function deleteFiles(int $protokollId): void
    {
        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(self::dir($protokollId));
        DB::connection('tenant')->table('checklisten_fotos')->where('protokoll_id', $protokollId)->delete();
    }

    // ------------------------------------------------------------------
    // Industry template packs (installed with the Branchen-Paket).

    public static function f(string $id, string $typ, string $label, array $x = []): array
    {
        return ['id' => $id, 'typ' => $typ, 'label' => $label] + $x;
    }

    public static function nok(string $id, string $label, bool $pflicht = true): array
    {
        return self::f($id, 'ok_nok', $label, ['pflicht' => $pflicht, 'foto' => 'bei_mangel']);
    }

    public static function paketVorlagen(string $branche): array
    {
        $f = [self::class, 'f'];
        $nok = [self::class, 'nok'];
        $bemerkung = $f('bem', 'text', 'Bemerkungen');
        $winter = [
            'key' => 'winterdienst.protokoll', 'name' => 'Winterdienst-Protokoll (Räum- und Streunachweis)', 'kategorie' => 'winterdienst',
            'beschreibung' => 'Nachweis der Verkehrssicherungspflicht: wann, wo, wie geräumt/gestreut wurde. Mit GPS und Zeitstempel.',
            'unterschrift' => 'mitarbeiter',
            'felder' => [
                $f('beginn', 'zeit', 'Beginn', ['pflicht' => true]),
                $f('ende', 'zeit', 'Ende', ['pflicht' => true]),
                $f('wetter', 'auswahl', 'Wetterlage', ['pflicht' => true, 'optionen' => ['Schneefall', 'Glätte / Glatteis', 'Reifglätte', 'Eisregen', 'Kontrolle ohne Einsatz']]),
                $f('temp', 'zahl', 'Temperatur', ['einheit' => '°C']),
                $f('taetigkeit', 'auswahl', 'Tätigkeit', ['pflicht' => true, 'optionen' => ['Räumen', 'Streuen', 'Räumen & Streuen', 'Kontrolle']]),
                $f('mittel', 'auswahl', 'Streumittel', ['optionen' => ['Splitt', 'Granulat', 'Salz', 'Sole', 'Keines']]),
                $f('menge', 'zahl', 'Streumenge', ['einheit' => 'kg']),
                $f('a1', 'abschnitt', 'Bearbeitete Flächen'),
                $f('gehwege', 'check', 'Gehwege & Zugänge'),
                $f('parken', 'check', 'Parkflächen & Zufahrten'),
                $f('treppen', 'check', 'Treppen & Eingänge'),
                $f('foto', 'foto', 'Nachweisfoto', ['foto' => 'optional']),
                $bemerkung,
            ],
        ];
        $p = [
            'gebaeudereinigung' => [
                [
                    'key' => 'gebaeudereinigung.qk', 'name' => 'Qualitätskontrolle Unterhaltsreinigung', 'kategorie' => 'qualitaet',
                    'beschreibung' => 'Stichprobenkontrolle nach dem Prinzip der DIN EN 13549 — Mängel mit Foto und Beschreibung, Ergebnis in Prozent.',
                    'unterschrift' => 'beide',
                    'felder' => [
                        $f('a1', 'abschnitt', 'Büros & Arbeitsplätze'),
                        $nok('b1', 'Böden gereinigt (Staub, Flecken, Ränder)'),
                        $nok('b2', 'Schreibtische & Ablagen staubfrei'),
                        $nok('b3', 'Papierkörbe geleert, Beutel gewechselt'),
                        $f('a2', 'abschnitt', 'Sanitärbereiche'),
                        $nok('s1', 'WC & Urinale gereinigt und desinfiziert'),
                        $nok('s2', 'Waschbecken & Armaturen sauber, kalkfrei'),
                        $nok('s3', 'Spiegel & Fliesen streifenfrei'),
                        $nok('s4', 'Verbrauchsmaterial aufgefüllt (Seife, Papier)'),
                        $f('a3', 'abschnitt', 'Küche / Teeküche'),
                        $nok('k1', 'Arbeitsflächen & Spüle sauber', false),
                        $nok('k2', 'Geräte-Außenflächen gereinigt', false),
                        $f('a4', 'abschnitt', 'Allgemeinflächen'),
                        $nok('g1', 'Eingangsbereich & Sauberlaufzone'),
                        $nok('g2', 'Treppen & Handläufe', false),
                        $f('gesamt', 'bewertung', 'Gesamteindruck', ['pflicht' => true, 'foto' => 'bei_mangel']),
                        $bemerkung,
                    ],
                ],
                [
                    'key' => 'gebaeudereinigung.ln', 'name' => 'Leistungsnachweis Reinigung', 'kategorie' => 'leistungsnachweis',
                    'beschreibung' => 'Bestätigung der erbrachten Reinigungsleistung durch den Kunden vor Ort.',
                    'unterschrift' => 'kunde',
                    'felder' => [
                        $f('lv', 'check', 'Unterhaltsreinigung laut Leistungsverzeichnis durchgeführt', ['pflicht' => true]),
                        $f('sonder', 'check', 'Sonderleistungen erbracht'),
                        $f('sonder_txt', 'text', 'Welche Sonderleistungen?'),
                        $f('schluessel', 'auswahl', 'Schlüssel', ['optionen' => ['Zurückgegeben', 'Verbleibt beim Mitarbeiter', 'Kein Schlüssel']]),
                        $bemerkung,
                    ],
                ],
            ],
            'hauswirtschaft' => [
                [
                    'key' => 'hauswirtschaft.ln', 'name' => 'Leistungsnachweis Hausbesuch (§ 45b SGB XI)', 'kategorie' => 'leistungsnachweis',
                    'beschreibung' => 'Nachweis je Besuch für die Abrechnung mit der Pflegekasse (Entlastungsbetrag) — mit Unterschrift des Klienten.',
                    'unterschrift' => 'beide',
                    'felder' => [
                        $f('beginn', 'zeit', 'Besuch Beginn', ['pflicht' => true]),
                        $f('ende', 'zeit', 'Besuch Ende', ['pflicht' => true]),
                        $f('a1', 'abschnitt', 'Erbrachte Leistungen'),
                        $f('l1', 'check', 'Reinigung der Wohnräume'),
                        $f('l2', 'check', 'Bad & WC'),
                        $f('l3', 'check', 'Küche & Geschirr'),
                        $f('l4', 'check', 'Wäsche waschen / bügeln'),
                        $f('l5', 'check', 'Einkauf & Besorgungen'),
                        $f('l6', 'check', 'Begleitung (Arzt, Behörde, Spaziergang)'),
                        $f('l7', 'check', 'Betreuung, Gespräch, Aktivierung'),
                        $f('l8', 'text', 'Sonstige Leistungen'),
                        $f('a2', 'abschnitt', 'Beobachtungen'),
                        $f('befinden', 'auswahl', 'Befinden', ['optionen' => ['Gut', 'Unverändert', 'Auffällig — Büro informieren']]),
                        $f('hinweis', 'text', 'Hinweise für Büro / Angehörige'),
                    ],
                ],
            ],
            'facility_management' => [
                [
                    'key' => 'facility_management.kontrollgang', 'name' => 'Hausmeister-Kontrollgang', 'kategorie' => 'rundgang',
                    'beschreibung' => 'Regelmäßige Objektbegehung — Mängel mit Foto für die Nachverfolgung.',
                    'unterschrift' => 'keine',
                    'felder' => [
                        $nok('k1', 'Beleuchtung innen & außen'),
                        $nok('k2', 'Türen, Schlösser, Fluchtwege frei'),
                        $nok('k3', 'Feuerlöscher vorhanden, Prüfplakette gültig'),
                        $nok('k4', 'Heizung / Lüftung ohne Störung'),
                        $nok('k5', 'Aufzug in Betrieb', false),
                        $nok('k6', 'Außenanlagen & Müllplatz'),
                        $f('massnahmen', 'text', 'Festgestellte Mängel & Maßnahmen'),
                    ],
                ],
                [
                    'key' => 'facility_management.wartung', 'name' => 'Wartungs- & Prüfprotokoll', 'kategorie' => 'wartung',
                    'beschreibung' => 'Protokoll je Anlage/Gerät (Wartung, Inspektion, Prüfung) mit Ergebnis und nächstem Termin.',
                    'unterschrift' => 'mitarbeiter',
                    'felder' => [
                        $f('anlage', 'text', 'Anlage / Gerät', ['pflicht' => true]),
                        $f('standort', 'text', 'Standort'),
                        $f('art', 'auswahl', 'Art', ['pflicht' => true, 'optionen' => ['Wartung', 'Inspektion', 'Prüfung', 'Instandsetzung']]),
                        $f('sicht', 'check', 'Sichtprüfung durchgeführt'),
                        $f('funktion', 'check', 'Funktionsprüfung durchgeführt'),
                        $nok('ergebnis', 'Ergebnis'),
                        $f('naechste', 'auswahl', 'Nächste Prüfung in', ['optionen' => ['1 Monat', '3 Monaten', '6 Monaten', '12 Monaten', '24 Monaten']]),
                        $f('material', 'text', 'Ersatzteile / Material'),
                        $f('typenschild', 'foto', 'Foto Typenschild', ['foto' => 'optional']),
                    ],
                ],
                $winter,
            ],
            'gartenpflege' => [
                $winter,
                [
                    'key' => 'gartenpflege.ln', 'name' => 'Leistungsnachweis Grünpflege', 'kategorie' => 'leistungsnachweis',
                    'beschreibung' => 'Erbrachte Pflegeleistungen mit Vorher-/Nachher-Fotos.',
                    'unterschrift' => 'keine',
                    'felder' => [
                        $f('l1', 'check', 'Rasen gemäht'),
                        $f('l2', 'check', 'Hecken / Sträucher geschnitten'),
                        $f('l3', 'check', 'Beete gepflegt, Unkraut entfernt'),
                        $f('l4', 'check', 'Laub entfernt'),
                        $f('l5', 'check', 'Grünschnitt entsorgt'),
                        $f('menge', 'zahl', 'Menge Grünschnitt', ['einheit' => 'm³']),
                        $f('foto', 'foto', 'Vorher / Nachher', ['foto' => 'optional']),
                        $bemerkung,
                    ],
                ],
            ],
            'sicherheitsdienst' => [
                [
                    'key' => 'sicherheitsdienst.kontrollgang', 'name' => 'Kontrollgang', 'kategorie' => 'rundgang',
                    'beschreibung' => 'Rundgang mit Zeitstempel und GPS — Auffälligkeiten mit Foto.',
                    'unterschrift' => 'keine',
                    'felder' => [
                        $nok('r1', 'Zugänge & Türen verschlossen'),
                        $nok('r2', 'Fenster geschlossen'),
                        $nok('r3', 'Beleuchtung in Ordnung'),
                        $nok('r4', 'Alarmanlage scharf geschaltet', false),
                        $nok('r5', 'Keine Fremdpersonen / Auffälligkeiten'),
                        $nok('r6', 'Fluchtwege frei, Brandschutz in Ordnung'),
                        $f('vorkommnisse', 'text', 'Besondere Vorkommnisse'),
                    ],
                ],
            ],
            'veranstaltungsdienst' => [
                [
                    'key' => 'veranstaltungsdienst.position', 'name' => 'Positionscheck Veranstaltung', 'kategorie' => 'rundgang',
                    'beschreibung' => 'Kontrolle zu Dienstbeginn an der zugewiesenen Position.',
                    'unterschrift' => 'keine',
                    'felder' => [
                        $f('c1', 'check', 'Briefing erhalten', ['pflicht' => true]),
                        $f('c2', 'check', 'Funkgerät getestet'),
                        $f('c3', 'check', 'Fluchtwege & Notausgänge begangen'),
                        $nok('c4', 'Position besetzt und einsatzbereit'),
                        $f('vorkommnisse', 'text', 'Vorkommnisse'),
                    ],
                ],
            ],
            'personaldienstleistung' => [
                [
                    'key' => 'personaldienstleistung.feedback', 'name' => 'Einsatz-Feedback Kundenbetrieb', 'kategorie' => 'qualitaet',
                    'beschreibung' => 'Bewertung des Einsatzes durch den Kundenbetrieb (Entleiher).',
                    'unterschrift' => 'kunde',
                    'felder' => [
                        $f('p1', 'bewertung', 'Pünktlichkeit', ['pflicht' => true]),
                        $f('p2', 'bewertung', 'Arbeitsqualität', ['pflicht' => true]),
                        $f('p3', 'bewertung', 'Arbeitssicherheit & PSA', ['pflicht' => true]),
                        $f('bem', 'text', 'Bemerkungen des Kundenbetriebs'),
                    ],
                ],
            ],
        ];
        return $p[$branche] ?? [];
    }

    /**
     * Installs the industry's templates for main $bereichId (missing keys
     * only — a template the owner edited or deleted is never re-created
     * while a row with that key exists). Returns the number created.
     */
    public static function installPaket(string $branche, ?int $bereichId, ?string $by = null): int
    {
        if (!self::ready()) return 0;
        $db = DB::connection('tenant');
        $n = 0;
        foreach (self::paketVorlagen($branche) as $v) {
            $key = $v['key'] . ($bereichId ? '@' . $bereichId : '');
            if ($db->table('checklisten_vorlagen')->where('vorlage_key', $key)->exists()) continue;
            $felder = self::cleanFelder($v['felder']);
            if (!is_array($felder)) continue;
            $db->table('checklisten_vorlagen')->insert([
                'name' => $v['name'], 'beschreibung' => $v['beschreibung'] ?? null, 'kategorie' => $v['kategorie'],
                'bereich_id' => $bereichId, 'alle_objekte' => 1,
                'felder' => json_encode($felder, JSON_UNESCAPED_UNICODE),
                'unterschrift' => $v['unterschrift'] ?? 'keine', 'gps' => 1, 'im_kundenportal' => 1, 'aktiv' => 1,
                'version' => 1, 'vorlage_key' => $key, 'created_by' => $by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $n++;
        }
        return $n;
    }

    /** Pack summary for the UI (names only). */
    public static function paketNamen(string $branche): array
    {
        return array_map(fn ($v) => $v['name'], self::paketVorlagen($branche));
    }
}
