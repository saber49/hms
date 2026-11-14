<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform catalog of security service types (tenant table `sicherheitsleistungen`)
 * — the same fixed keys for every company of the Sicherheitsbranche.
 *
 * Each company names its own Einsatzarten (subs of the security main in
 * `bereiche_katalog`); every Einsatzart is mapped once to one platform type
 * (`bereiche_katalog.leistungsart_id`). An article's type
 * (`artikel.leistungsart_id`) is DERIVED from its Einsatzarten (fallback: its
 * object's Einsatzarten) — users never pick it separately.
 */
final class SecurityServices
{
    /** Platform types in display order (key => name). Keys are stable. */
    public const DEFAULTS = [
        'objektschutz'          => 'Objektschutz / Separatwachdienst',
        'werkschutz'            => 'Werkschutz',
        'empfangsdienst'        => 'Empfangs- & Pfortendienst',
        'revierdienst'          => 'Revier- & Streifendienst',
        'city_streife'          => 'City-Streife / Kontrollgänge im öffentlichen Raum',
        'oepnv'                 => 'Sicherheitsdienst ÖPNV / Bahnhof',
        'ladendetektiv'         => 'Ladendetektiv / Schutz vor Ladendieben',
        'doorman'               => 'Doorman / Einlassdienst Gastronomie',
        'asylunterkunft'        => 'Bewachung Flüchtlings- / Asylunterkunft',
        'veranstaltungsschutz'  => 'Veranstaltungsschutz / Einlasskontrolle',
        'stadiondienst'         => 'Stadion- & Ordnerdienst',
        'baustellenbewachung'   => 'Baustellenbewachung',
        'alarmverfolgung'       => 'Alarmverfolgung & Intervention',
        'personenschutz'        => 'Personenschutz',
        'nsl'                   => 'Notruf- & Serviceleitstelle (NSL)',
        'brandsicherheitswache' => 'Brandsicherheitswache',
        'veranstaltungsservice' => 'Veranstaltungsservice (Garderobe, Auf-/Abbau)',
        'sonstiges'             => 'Sonstige Sicherheitsdienstleistung',
    ];

    /** § 34a Abs. 1a Satz 2 GewO — Sachkundeprüfung required (Nr. 1–5). */
    public const SACHKUNDE = [
        'city_streife'         => 'Sachkundeprüfung erforderlich (§ 34a Abs. 1a Nr. 1 GewO)',
        'oepnv'                => 'Sachkundeprüfung erforderlich (§ 34a Abs. 1a Nr. 1 GewO)',
        'ladendetektiv'        => 'Sachkundeprüfung erforderlich (§ 34a Abs. 1a Nr. 2 GewO)',
        'doorman'              => 'Sachkundeprüfung erforderlich (§ 34a Abs. 1a Nr. 3 GewO)',
        'asylunterkunft'       => 'Sachkundeprüfung in leitender Funktion (§ 34a Abs. 1a Nr. 4 GewO)',
        'veranstaltungsschutz' => 'Sachkundeprüfung in leitender Funktion bei zugangsgeschützten Großveranstaltungen (§ 34a Abs. 1a Nr. 5 GewO)',
        'stadiondienst'        => 'Sachkundeprüfung in leitender Funktion bei zugangsgeschützten Großveranstaltungen (§ 34a Abs. 1a Nr. 5 GewO)',
    ];

    /** Other notes shown next to the type. */
    public const HINWEIS = [
        'werkschutz'            => 'Bei Objekten mit besonderer Gefahr für die Allgemeinheit: erweiterte Zuverlässigkeitsprüfung (§ 34a Abs. 1a Satz 5 GewO)',
        'brandsicherheitswache' => 'Keine Bewachung im Sinne von § 34a — Brandschutz-Qualifikation erforderlich',
        'veranstaltungsservice' => 'Keine Bewachung — § 34a nicht erforderlich',
    ];

    /** Default Einsatzarten for a new company (name => platform key). */
    public const STARTER_EINSATZARTEN = [
        'Objektschutz'                   => 'objektschutz',
        'Werkschutz'                     => 'werkschutz',
        'Empfangs- & Pfortendienst'      => 'empfangsdienst',
        'Revier- & Streifendienst'       => 'revierdienst',
        'Alarmverfolgung & Intervention' => 'alarmverfolgung',
        'Baustellenbewachung'            => 'baustellenbewachung',
        'Veranstaltungsschutz'           => 'veranstaltungsschutz',
        'Doorman / Einlassdienst'        => 'doorman',
    ];

    /** Keyword rules for suggestions — first match wins, order matters. */
    private const RULES = [
        'stadiondienst'         => ['stadion', 'ordnerdienst', 'ordnerpersonal', 'dfb'],
        'veranstaltungsservice' => ['garderobe', 'messebau', 'messenaufbau', 'auf- und abbau', 'aufbau', 'abbau', 'veranstaltungsservice'],
        'doorman'               => ['doorman', 'dormann', 'türsteher', 'tuersteher', 'diskothek', 'einlassdienst'],
        'asylunterkunft'        => ['flüchtling', 'fluechtling', 'asyl', 'erstaufnahme', 'gemeinschaftsunterkunft'],
        'ladendetektiv'         => ['ladendetektiv', 'detektiv', 'ladendieb', 'kaufhausdetektiv'],
        'oepnv'                 => ['öpnv', 'oepnv', 'bahnhof', 'nahverkehr'],
        'city_streife'          => ['city-streife', 'citystreife', 'city streife', 'innenstadt', 'öffentlichen raum'],
        'brandsicherheitswache' => ['brandwache', 'brandsicherheit', 'brandschutzwache'],
        'personenschutz'        => ['personenschutz', 'bodyguard'],
        'nsl'                   => ['leitstelle', 'notruf'],
        'alarmverfolgung'       => ['alarm', 'intervention'],
        'veranstaltungsschutz'  => ['veranstalt', 'event', 'konzert', 'festival', 'messe', 'einlass'],
        'revierdienst'          => ['revier', 'streife', 'kontrollfahrt', 'schliessdienst', 'schließdienst'],
        'baustellenbewachung'   => ['baustell'],
        'empfangsdienst'        => ['empfang', 'pforte', 'pfort', 'rezeption', 'concierge'],
        'objektschutz'          => ['objektschutz', 'separatwach', 'bewachung', 'wachdienst', 'wachschutz', 'sicherheit', 'security', 'sma/u', '34a'],
        'werkschutz'            => ['werkschutz', 'werkstor', 'torwache'],
    ];

    public static function ready(): bool
    {
        $s = Schema::connection('tenant');
        return $s->hasTable('sicherheitsleistungen') && $s->hasColumn('artikel', 'leistungsart_id');
    }

    /** Einsatzart → type mapping available (2026_10_28_120000). */
    public static function mappingReady(): bool
    {
        return self::ready() && Schema::connection('tenant')->hasColumn('bereiche_katalog', 'leistungsart_id');
    }

    /** @return array<int,array{id:int,schluessel:string,name:string,sachkunde:bool,hinweis:?string}> */
    public static function active(): array
    {
        if (!self::ready()) return [];
        return DB::connection('tenant')->table('sicherheitsleistungen')->where('aktiv', 1)
            ->orderBy('sortierung')->orderBy('name')->get(['id', 'schluessel', 'name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'schluessel' => $r->schluessel, 'name' => $r->name,
                'sachkunde' => isset(self::SACHKUNDE[$r->schluessel]),
                'hinweis' => self::SACHKUNDE[$r->schluessel] ?? self::HINWEIS[$r->schluessel] ?? null])->all();
    }

    public static function isActiveId(mixed $id): bool
    {
        return self::ready() && is_numeric($id) && (int) $id == $id
            && DB::connection('tenant')->table('sicherheitsleistungen')->where('id', (int) $id)->where('aktiv', 1)->exists();
    }

    /** Suggested service type key for a free-text label, or null. */
    public static function guess(?string ...$texts): ?string
    {
        $t = mb_strtolower(implode(' ', array_filter($texts)));
        if (trim($t) === '') return null;
        foreach (self::RULES as $key => $needles) {
            foreach ($needles as $n) {
                if (str_contains($t, $n)) return $key;
            }
        }
        return null;
    }

    public static function idForKey(?string $key): ?int
    {
        if (!$key || !self::ready()) return null;
        $id = DB::connection('tenant')->table('sicherheitsleistungen')->where('schluessel', $key)->where('aktiv', 1)->value('id');
        return $id ? (int) $id : null;
    }

    /** @return array<string,int> lowercased Einsatzart name => platform type id (mapped subs only) */
    public static function einsatzartMap(): array
    {
        if (!self::mappingReady()) return [];
        $map = [];
        foreach (DB::connection('tenant')->table('bereiche_katalog')->whereNotNull('parent_id')
                     ->whereNotNull('leistungsart_id')->get(['name', 'leistungsart_id']) as $r) {
            $map[mb_strtolower(trim($r->name))] ??= (int) $r->leistungsart_id;
        }
        return $map;
    }

    /** Type of the first mapped Einsatzart in $arten, else of the object's Einsatzarten. */
    public static function derive(array $arten, ?array $objektArten, ?array $map = null): ?int
    {
        $map ??= self::einsatzartMap();
        foreach ([$arten, $objektArten ?? []] as $list) {
            foreach ($list as $a) {
                $id = $map[mb_strtolower(trim((string) $a))] ?? null;
                if ($id) return $id;
            }
        }
        return null;
    }

    /**
     * Recomputes artikel.leistungsart_id from the Einsatzarten (all articles,
     * or the given ids). Returns the number of changed rows.
     */
    public static function syncArtikel(?array $artikelIds = null): int
    {
        if (!self::mappingReady()) return 0;
        $db = DB::connection('tenant');
        $map = self::einsatzartMap();
        $list = fn ($json) => array_values(array_filter((array) (json_decode((string) $json, true) ?: []), 'is_string'));
        $objArten = $db->table('objekte')->pluck('arten', 'obj_id')->map($list)->all();
        $q = $db->table('artikel')->select('artikel_id', 'obj_id', 'arten', 'leistungsart_id');
        if ($artikelIds !== null) $q->whereIn('artikel_id', $artikelIds);
        $changed = 0;
        foreach ($q->get() as $a) {
            $new = self::derive($list($a->arten), $objArten[$a->obj_id] ?? [], $map);
            if ($new !== ($a->leistungsart_id !== null ? (int) $a->leistungsart_id : null)) {
                $db->table('artikel')->where('artikel_id', $a->artikel_id)->update(['leistungsart_id' => $new]);
                $changed++;
            }
        }
        return $changed;
    }

    /** Creates the starter Einsatzarten (mapped) under a main that has none yet. */
    public static function seedStarterEinsatzarten(int $mainId): int
    {
        if (!self::mappingReady()) return 0;
        $db = DB::connection('tenant');
        if ($db->table('bereiche_katalog')->where('parent_id', $mainId)->exists()) return 0;
        $i = 0;
        foreach (self::STARTER_EINSATZARTEN as $name => $key) {
            $db->table('bereiche_katalog')->insert(['name' => $name, 'parent_id' => $mainId, 'aktiv' => 1, 'sortierung' => ++$i,
                'leistungsart_id' => self::idForKey($key), 'created_at' => now(), 'updated_at' => now()]);
        }
        return $i;
    }
}
