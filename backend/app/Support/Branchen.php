<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Industry registry per Hauptbereich (`bereiche_katalog.branche`).
 *
 * One company may run several industries (e.g. Sicherheit + Reinigung) —
 * the industry therefore lives on each main Bereich. Every entry carries:
 *   terms   → UI wording for the company's main industry (Kunde/Klient …)
 *   flags   → bewachung / stadiondienst switches of the Bereich
 *   module  → feature keys later phases gate on (checklisten, turnus …)
 *   paket   → starter pack: Objektarten (subs), Qualifikations- and
 *             Kleidungs-Listen. Applying it only ADDS missing names.
 *
 * Keys are the same as TenantProfile::BRANCHEN (registration choices).
 */
class Branchen
{
    public const DEFAULT_TERMS = [
        'kunde' => 'Kunde', 'kunden' => 'Kunden',
        'objekt' => 'Objekt', 'objekte' => 'Objekte',
    ];

    public const TERM_KEYS = ['kunde', 'kunden', 'objekt', 'objekte'];

    private const ERSTE_HILFE = 'Erste-Hilfe-Ausbildung (DGUV)';

    public const REGISTRY = [
        'gebaeudereinigung' => [
            'label' => 'Gebäudereinigung', 'icon' => 'cleaning_services',
            'terms' => [],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => ['checklisten', 'turnus', 'flaechen', 'leistungsverzeichnis', 'qualitaet'],
            'paket' => [
                'subs' => ['Unterhaltsreinigung', 'Glas- & Fassadenreinigung', 'Grundreinigung', 'Bauendreinigung',
                    'Praxis- & Klinikreinigung', 'Schul- & Kitareinigung', 'Industriereinigung', 'Treppenhausreinigung'],
                'quals' => [
                    ['name' => 'Fachqualifikation Reinigung', 'opts' => ['Gebäudereiniger/in (Geselle)', 'Gebäudereinigermeister/in',
                        'Desinfektor/in', 'Hygieneschulung (KRINKO/RKI)', 'Höhenzugangstechnik / PSAgA',
                        'Hubarbeitsbühne (DGUV G 308-008)', 'Einweisung Reinigungsmaschinen']],
                    ['name' => 'Unterweisungen', 'alias' => ['Schulungen', 'Unterweisung'], 'opts' => ['Arbeitsschutz-Unterweisung (jährlich)',
                        'Gefahrstoff-Unterweisung (§ 14 GefStoffV)', self::ERSTE_HILFE]],
                ],
                'kleidung' => [
                    ['name' => 'Dienstkleidung', 'opts' => ['Poloshirt mit Logo', 'Kasack', 'Arbeitshose', 'Sicherheitsschuhe S2']],
                ],
            ],
        ],
        'sicherheitsdienst' => [
            'label' => 'Sicherheitsdienst', 'icon' => 'shield',
            'terms' => [],
            'flags' => ['bewachung' => true, 'stadiondienst' => false],
            'module' => ['checklisten', 'turnus', 'rundgaenge', 'wachbuch'],
            'paket' => [
                'subs' => ['Objektschutz', 'Revierdienst', 'Empfangs- & Pfortendienst', 'Baustellenbewachung', 'Werkschutz'],
                'quals' => [
                    ['name' => '§ 34 GewO', 'alias' => ['§34a', '§ 34a', '34a', '§ 34a GewO', '§34a GewO', 'Bewachung'], 'opts' => ['Unterrichtung (§34a)', 'Sachkundeprüfung (§34a)',
                        'Geprüfte Schutz- und Sicherheitskraft (GSSK)', 'Fachkraft für Schutz und Sicherheit']],
                    ['name' => 'Weitere Nachweise', 'opts' => [self::ERSTE_HILFE, 'Brandschutzhelfer/in (DGUV I 205-023)', 'Deeskalationstraining']],
                ],
                'kleidung' => [
                    ['name' => 'Dienstkleidung', 'opts' => ['Uniform', 'Security-Weste', 'Schwarze Hose', 'Sicherheitsschuhe']],
                ],
            ],
        ],
        'veranstaltungsdienst' => [
            'label' => 'Veranstaltungs- & Ordnerdienst', 'icon' => 'stadium',
            'terms' => [],
            'flags' => ['bewachung' => true, 'stadiondienst' => true],
            'module' => ['checklisten', 'wachbuch'],
            'paket' => [
                'subs' => ['Einlass & Kontrolle', 'Ordnerdienst', 'Stadion', 'Konzert & Festival', 'Messe & Kongress'],
                'quals' => [
                    ['name' => '§ 34 GewO', 'alias' => ['§34a', '§ 34a', '34a', '§ 34a GewO', '§34a GewO', 'Bewachung'], 'opts' => ['Unterrichtung (§34a)', 'Sachkundeprüfung (§34a)',
                        'Geprüfte Schutz- und Sicherheitskraft (GSSK)', 'Fachkraft für Schutz und Sicherheit']],
                    ['name' => 'DFB Schulung', 'alias' => ['DFB', 'DFB-Schulung', 'QuaSOD'], 'opts' => ['Modul 1 - E-Learning', 'Modul 1 - Prüfung', 'Modul 2 - Ortskunde',
                        'Modul 3 - Brandschutz', 'Modul 4 - Erste-Hilfe', 'Modul 5 - Deeskalation', 'Modul 6 - Einlass',
                        'Modul 7 - Führungskräfte', 'Modul 8 - Einsatzbereitschaft', 'Modul 9 - VIP']],
                ],
                'kleidung' => [
                    ['name' => 'Dienstkleidung', 'opts' => ['Ordnerweste', 'Schwarze Kleidung', 'Security-Jacke']],
                ],
            ],
        ],
        'personaldienstleistung' => [
            'label' => 'Personaldienstleistung / Zeitarbeit', 'icon' => 'badge',
            'terms' => ['objekt' => 'Einsatzbetrieb', 'objekte' => 'Einsatzbetriebe'],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => ['aueg'],
            'paket' => [
                'subs' => ['Lager & Logistik', 'Produktion', 'Handwerk & Technik', 'Büro & Verwaltung', 'Gastronomie & Hotellerie'],
                'quals' => [
                    ['name' => 'Fachqualifikationen', 'opts' => ['Staplerschein (DGUV G 308-001)', 'Kranschein (DGUV G 309-003)',
                        'Schweißerprüfung', 'Elektrofachkraft', 'Fahrerlaubnis C/CE', 'Berufskraftfahrer-Qualifikation (Modul 95)']],
                    ['name' => 'Unterweisungen', 'opts' => ['Arbeitsschutz-Unterweisung (jährlich)', self::ERSTE_HILFE]],
                ],
                'kleidung' => [
                    ['name' => 'Arbeitskleidung', 'opts' => ['Sicherheitsschuhe S3', 'Warnweste', 'Arbeitshose', 'Gehörschutz']],
                ],
            ],
        ],
        'facility_management' => [
            'label' => 'Facility Management', 'icon' => 'apartment',
            'terms' => ['objekt' => 'Liegenschaft', 'objekte' => 'Liegenschaften'],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => ['checklisten', 'turnus', 'anlagen', 'wartung', 'tickets'],
            'paket' => [
                'subs' => ['Hausmeisterdienst', 'Technische Gebäudeausrüstung (TGA)', 'Empfang & Service',
                    'Grünpflege', 'Winterdienst', 'Objektbetreuung'],
                'quals' => [
                    ['name' => 'Technische Qualifikationen', 'opts' => ['Elektrofachkraft (EFK)', 'Elektrotechnisch unterwiesene Person (EuP)',
                        'Trinkwasserhygiene (VDI 6023)', 'SHK-Fachkraft', 'Hubarbeitsbühne (DGUV G 308-008)', 'Staplerschein (DGUV G 308-001)']],
                    ['name' => 'Unterweisungen', 'opts' => ['Arbeitsschutz-Unterweisung (jährlich)', 'Brandschutzhelfer/in (DGUV I 205-023)', self::ERSTE_HILFE]],
                ],
                'kleidung' => [
                    ['name' => 'Arbeitskleidung', 'opts' => ['Arbeitsjacke mit Logo', 'Arbeitshose', 'Sicherheitsschuhe S3']],
                ],
            ],
        ],
        'gartenpflege' => [
            'label' => 'Garten- & Landschaftspflege', 'icon' => 'park',
            'terms' => ['objekt' => 'Grundstück', 'objekte' => 'Grundstücke'],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => ['checklisten', 'turnus', 'flaechen', 'winterdienst'],
            'paket' => [
                'subs' => ['Grünflächenpflege', 'Baum- & Heckenpflege', 'Winterdienst', 'Neuanlage', 'Spielplatzpflege'],
                'quals' => [
                    ['name' => 'Fachqualifikationen', 'opts' => ['Motorsägenschein (DGUV I 214-059)', 'Sachkunde Pflanzenschutz',
                        'Baumkontrolleur/in (FLL)', 'Spielplatzprüfer/in (DIN EN 1176)', 'Fahrerlaubnis BE', 'Fahrerlaubnis C1/C1E']],
                    ['name' => 'Unterweisungen', 'opts' => ['Arbeitsschutz-Unterweisung (jährlich)', 'Einweisung Freischneider/Mäher', self::ERSTE_HILFE]],
                ],
                'kleidung' => [
                    ['name' => 'Schutzkleidung', 'opts' => ['Schnittschutzhose', 'Warnkleidung (EN ISO 20471)', 'Gehörschutz', 'Sicherheitsschuhe S3']],
                ],
            ],
        ],
        'hauswirtschaft' => [
            'label' => 'Hauswirtschaft & Alltagshilfe', 'icon' => 'volunteer_activism',
            'terms' => ['kunde' => 'Klient', 'kunden' => 'Klienten', 'objekt' => 'Haushalt', 'objekte' => 'Haushalte'],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => ['checklisten', 'turnus', 'pflegekasse', 'unterschrift'],
            'paket' => [
                'subs' => ['Haushaltshilfe', 'Alltagsbegleitung', 'Betreuung (§ 45a SGB XI)', 'Einkaufs- & Begleitdienst', 'Wäscheservice'],
                'quals' => [
                    ['name' => 'Qualifikationen', 'opts' => ['Hauswirtschafter/in', 'Alltagsbegleiter/in (§ 53b SGB XI)',
                        'Pflegebasiskurs', 'Demenz-Schulung', self::ERSTE_HILFE]],
                    ['name' => 'Nachweise', 'opts' => ['Erweitertes Führungszeugnis', 'Belehrung Infektionsschutz (§ 43 IfSG)',
                        'Schulung nach Landesrecht (Anerkennung Angebote zur Unterstützung im Alltag)']],
                ],
                'kleidung' => [
                    ['name' => 'Dienstkleidung', 'opts' => ['Poloshirt mit Logo', 'Kasack', 'Namensschild']],
                ],
            ],
        ],
        'sonstiges' => [
            'label' => 'Sonstiges', 'icon' => 'category',
            'terms' => [],
            'flags' => ['bewachung' => false, 'stadiondienst' => false],
            'module' => [],
            'paket' => ['subs' => [], 'quals' => [], 'kleidung' => []],
        ],
    ];

    public static function exists(?string $key): bool
    {
        return is_string($key) && isset(self::REGISTRY[$key]);
    }

    public static function get(?string $key): ?array
    {
        return self::exists($key) ? self::REGISTRY[$key] : null;
    }

    /** Registry for the UI (no closures, terms merged with defaults). */
    public static function forUi(): array
    {
        $out = [];
        foreach (self::REGISTRY as $key => $b) {
            $out[] = [
                'key' => $key, 'label' => $b['label'], 'icon' => $b['icon'],
                'terms' => array_merge(self::DEFAULT_TERMS, $b['terms']),
                'flags' => $b['flags'], 'module' => $b['module'],
                'paket' => [
                    'subs' => $b['paket']['subs'],
                    'quals' => array_map(fn ($l) => ['name' => $l['name'], 'anzahl' => count($l['opts'])], $b['paket']['quals']),
                    'kleidung' => array_map(fn ($l) => ['name' => $l['name'], 'anzahl' => count($l['opts'])], $b['paket']['kleidung']),
                    'checklisten' => Checklisten::paketNamen($key),
                ],
            ];
        }
        return $out;
    }

    /**
     * Wording for the company's MAIN industry (tenants.settings.branche):
     * custom override (settings.terms) > industry > neutral defaults.
     */
    public static function terms(?Tenant $tenant): array
    {
        $b = TenantProfile::branche($tenant);
        $terms = array_merge(self::DEFAULT_TERMS, $b ? self::REGISTRY[$b]['terms'] : []);
        $custom = $tenant ? data_get($tenant->settings, 'terms') : null;
        if (is_array($custom)) {
            foreach (self::TERM_KEYS as $k) {
                $v = trim((string) ($custom[$k] ?? ''));
                if ($v !== '') $terms[$k] = $v;
            }
        }
        return $terms;
    }

    /** Industry default wording without custom overrides (settings form placeholders). */
    public static function defaultTerms(?Tenant $tenant): array
    {
        $b = TenantProfile::branche($tenant);
        return array_merge(self::DEFAULT_TERMS, $b ? self::REGISTRY[$b]['terms'] : []);
    }

    /** Best guess for an existing main without industry (migration backfill). */
    public static function guess(string $name, bool $bewachung, bool $stadiondienst): ?string
    {
        $n = mb_strtolower($name);
        $rules = [
            'veranstaltungsdienst'   => ['veranstalt', 'ordner', 'event', 'stadion'],
            'sicherheitsdienst'      => ['sicherheit', 'bewach', 'security', 'objektschutz', 'wachdienst'],
            'gebaeudereinigung'      => ['reinig', 'clean'],
            'facility_management'    => ['facility', 'hausmeist', 'haustechn', 'gebäudemanagement', 'gebaeudemanagement'],
            'gartenpflege'           => ['garten', 'landschaft', 'grünpflege', 'gruenpflege', 'winterdienst'],
            'hauswirtschaft'         => ['hauswirt', 'alltag', 'haushalt', 'betreuung', 'seniorenhilfe'],
            'personaldienstleistung' => ['personaldienst', 'zeitarbeit', 'arbeitnehmerüberlassung', 'überlassung', 'personalleasing'],
        ];
        foreach ($rules as $key => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($n, $needle)) return $key;
            }
        }
        if ($stadiondienst) return 'veranstaltungsdienst';
        if ($bewachung) return 'sicherheitsdienst';
        return null;
    }

    /** Comparable form: lowercase, "(…)" dropped, only letters/digits. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(preg_replace('/\([^)]*\)/u', '', $s));
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
        return preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /** Same entry? Equal normal form, or one starts with the other (≥ 6 chars: "Sachkunde" ≈ "Sachkundeprüfung (§34a)"). */
    public static function same(string $a, string $b): bool
    {
        $x = self::norm($a);
        $y = self::norm($b);
        if ($x === '' || $y === '') return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        if ($x === $y) return true;
        [$short, $long] = strlen($x) <= strlen($y) ? [$x, $y] : [$y, $x];
        return strlen($short) >= 6 && str_starts_with($long, $short);
    }

    public static function hasColumn(): bool
    {
        $s = Schema::connection('tenant');
        return $s->hasTable('bereiche_katalog') && $s->hasColumn('bereiche_katalog', 'branche');
    }

    /**
     * Applies the starter pack of $key to main $mainId (tenant connection):
     * missing Objektarten, missing lists (+ their missing options) and the
     * industry switches (only switched ON, never off). Idempotent.
     * Returns counts of created rows.
     */
    public static function applyPaket(int $mainId, string $key): array
    {
        $b = self::get($key);
        $res = ['subs' => 0, 'listen' => 0, 'eintraege' => 0, 'flags' => [], 'checklisten' => 0];
        if (!$b) return $res;
        $db = DB::connection('tenant');
        $s = Schema::connection('tenant');
        $main = $db->table('bereiche_katalog')->where('id', $mainId)->whereNull('parent_id')->first();
        if (!$main) return $res;

        $db->transaction(function () use ($db, $s, $b, $key, $main, &$res) {
            $now = now();
            $haveSubs = $db->table('bereiche_katalog')->where('parent_id', $main->id)->pluck('name')
                ->map(fn ($n) => mb_strtolower(trim($n)))->all();
            $sort = (int) $db->table('bereiche_katalog')->where('parent_id', $main->id)->max('sortierung');
            foreach ($b['paket']['subs'] as $sub) {
                if (in_array(mb_strtolower($sub), $haveSubs, true)) continue;
                $db->table('bereiche_katalog')->insert(['name' => $sub, 'parent_id' => $main->id, 'aktiv' => 1,
                    'sortierung' => ++$sort, 'created_at' => $now, 'updated_at' => $now]);
                $res['subs']++;
            }

            if ($s->hasTable('bereich_qualifikationen')) {
                $hasTyp = $s->hasColumn('bereich_qualifikationen', 'typ');
                $groups = ['qualifikation' => $b['paket']['quals']];
                if ($hasTyp) $groups['kleidung'] = $b['paket']['kleidung'];
                foreach ($groups as $typ => $lists) {
                    $mainRows = $db->table('bereich_qualifikationen')->where('bereich_id', $main->id)
                        ->when($hasTyp, fn ($q) => $q->where('typ', $typ))->get(['id', 'parent_id', 'name']);
                    $existingOpts = $mainRows->whereNotNull('parent_id')->pluck('name')->all();
                    foreach ($lists as $list) {
                        $names = array_map([self::class, 'norm'], array_merge([$list['name']], $list['alias'] ?? []));
                        $listId = $mainRows->whereNull('parent_id')->first(fn ($r) => in_array(self::norm($r->name), $names, true))->id ?? null;
                        if (!$listId) {
                            $row = ['bereich_id' => $main->id, 'parent_id' => null, 'name' => $list['name'], 'aktiv' => 1,
                                'sortierung' => 0, 'created_at' => $now, 'updated_at' => $now];
                            if ($hasTyp) $row['typ'] = $typ;
                            $listId = $db->table('bereich_qualifikationen')->insertGetId($row);
                            $res['listen']++;
                        }
                        foreach ($list['opts'] as $i => $opt) {
                            foreach ($existingOpts as $have) {
                                if (self::same($have, $opt)) continue 2;
                            }
                            $existingOpts[] = $opt;
                            $row = ['bereich_id' => $main->id, 'parent_id' => $listId, 'name' => $opt, 'aktiv' => 1,
                                'sortierung' => $i, 'created_at' => $now, 'updated_at' => $now];
                            if ($hasTyp) $row['typ'] = $typ;
                            $db->table('bereich_qualifikationen')->insert($row);
                            $res['eintraege']++;
                        }
                    }
                }
            }

            $upd = [];
            if ($s->hasColumn('bereiche_katalog', 'bewachung')) {
                foreach (['bewachung', 'stadiondienst'] as $f) {
                    if ($b['flags'][$f] && !(int) ($main->$f ?? 0)) {
                        $upd[$f] = 1;
                        $res['flags'][] = $f;
                    }
                }
            }
            if (self::hasColumn() && ($main->branche ?? null) !== $key) $upd['branche'] = $key;
            if ($upd) {
                $upd['updated_at'] = $now;
                $db->table('bereiche_katalog')->where('id', $main->id)->update($upd);
            }
        });
        // Checklisten templates of the industry (QM, Leistungsnachweis, Winterdienst …).
        $res['checklisten'] = Checklisten::installPaket($key, (int) $main->id);
        return $res;
    }
}
