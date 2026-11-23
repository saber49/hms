<?php

namespace App\Support\Qm;

use App\Services\TenantService;
use App\Support\Crm;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Shared helpers of the Qualitätsmanagement module: tenant tables, request
 * memo (Octane-safe), settings, change log (qm_verlauf), number ranges,
 * validation with German messages.
 */
class Qm
{
    /** Default settings — QM-Einstellungen override them per tenant. */
    public const DEFAULTS = [
        'modul_wachbuch'        => '1',
        'modul_qm'              => '1',     // Dienstanweisung must be confirmed before check-in
        'portal_qm'             => '1',     // Kundenportal shows Dienstanweisungen + Reklamationen
        'portal_dienstanweisungen' => '1',
        'portal_reklamationen'  => '1',
        'portal_fotos'          => '1',
        'da_freigabe_pflicht'   => '1',     // Objektleiter drafts need QMB release
        'da_pruefung_monate'    => '12',
        'rek_bestaetigung_std'  => '24',    // Eingangsbestätigung within N hours
        'rek_frist_gering'      => '14',    // days to close, per Schwere
        'rek_frist_mittel'      => '7',
        'rek_frist_hoch'        => '3',
        'rek_frist_kritisch'    => '1',
        'mn_wirksam_tage'       => '30',    // effectiveness check after N days
        'mn_max_verlaengerung'  => '2',
        'terminal_sitzung_std'  => '12',
        'terminal_max_fehl'     => '5',
        'pin_max_fehl'          => '3',
        'tag_beginn'            => '06:00', // Wachbuch day 06:00–06:00
        'absender_email'        => '',
        'rechte'                => '',      // JSON override of QmRechte::STANDARD
    ];

    public static function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    /** Request-scoped memo keyed by tenant DB (never static — Octane). */
    public static function memo(string $key, callable $fn)
    {
        $bag = request()->attributes;
        $k = 'qm.' . DB::connection('tenant')->getDatabaseName() . '.' . $key;
        if (!$bag->has($k)) $bag->set($k, $fn());
        return $bag->get($k);
    }

    public static function forget(): void
    {
        $bag = request()->attributes;
        foreach (array_keys($bag->all()) as $k) {
            if (str_starts_with($k, 'qm.')) $bag->remove($k);
        }
    }

    public static function ready(): bool
    {
        return self::memo('ready', fn () => Schema::connection('tenant')->hasTable('qm_objekt_einstellungen'));
    }

    public static function heute(): string
    {
        return Carbon::now('Europe/Berlin')->toDateString();
    }

    /** Start (HH:MM, Berlin) of the Wachbuch day — setting tag_beginn. */
    public static function tagBeginn(): string
    {
        $v = (string) self::setting('tag_beginn', '06:00');
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '06:00';
    }

    /** Date of the running Wachbuch day: before tag_beginn it is still yesterday's. */
    public static function wachbuchTag(): string
    {
        $now = Carbon::now('Europe/Berlin');
        return $now->format('H:i') < self::tagBeginn() ? $now->copy()->subDay()->toDateString() : $now->toDateString();
    }

    /** UTC instant the running Wachbuch day started. */
    public static function wachbuchTagStart(): Carbon
    {
        return Carbon::parse(self::wachbuchTag() . ' ' . self::tagBeginn(), 'Europe/Berlin')->utc();
    }

    // ---------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------

    public static function settings(): array
    {
        return self::memo('settings', function () {
            $rows = self::ready() ? self::t('qm_einstellungen')->pluck('wert', 'schluessel')->all() : [];
            $s = array_merge(self::DEFAULTS, array_intersect_key($rows, self::DEFAULTS));
            // portal_qm was part of modul_qm before it became its own switch:
            // an unset value keeps following it, so nothing changes silently.
            if (!array_key_exists('portal_qm', $rows)) $s['portal_qm'] = $s['modul_qm'];
            // Preserve the previous combined choice until each section is configured separately.
            foreach (['portal_dienstanweisungen', 'portal_reklamationen'] as $key) {
                if (!array_key_exists($key, $rows)) $s[$key] = $s['portal_qm'];
            }
            return $s;
        });
    }

    public static function setting(string $key, $default = null)
    {
        return self::settings()[$key] ?? $default;
    }

    public static function intSetting(string $key, int $default = 0): int
    {
        $v = self::setting($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function boolSetting(string $key): bool
    {
        return (string) self::setting($key, '0') === '1';
    }

    public static function setSetting(string $key, ?string $value, $u): void
    {
        $alt = self::setting($key);
        if ((string) $alt === (string) $value) return;
        self::t('qm_einstellungen')->updateOrInsert(['schluessel' => $key], [
            'wert' => $value, 'updated_by' => $u->id ?? null, 'updated_at' => now(),
        ]);
        self::log('einstellungen', $key, 'geaendert', ['wert' => $alt], ['wert' => $value], $u);
        self::forget();
    }

    /** Settings of one Objekt (defaults when no row exists yet). */
    public static function objektEinstellung(string $objId): object
    {
        $row = self::t('qm_objekt_einstellungen')->where('obj_id', $objId)->first();
        return $row ?: (object) [
            'id' => null, 'obj_id' => $objId, 'wachbuch_aktiv' => 0, 'waechterkontrolle_aktiv' => 0,
            'fotopflicht' => 0, 'kunde_pdf' => 0, 'objektleiter_id' => null, 'bericht_empfaenger' => null, 'gps_grenze_m' => 150,
            'soll_begehung_tage' => null, 'soll_besuch_tage' => null, 'notfallkontakte' => null,
            'bericht_pflicht' => 0, 'bericht_ab' => null, 'durchgehend_bewacht' => 0,
            'besucher_ausweis_pflicht' => 0, 'lieferung_freigabe_pflicht' => 0,
            'uebergabe_checkliste' => 0, 'besuchszeit_ende' => null,
        ];
    }

    // ---------------------------------------------------------------
    // Change log + number ranges
    // ---------------------------------------------------------------

    /** Append one row to qm_verlauf (who, when, old, new). */
    public static function log(string $tabelle, $id, string $aktion, ?array $alt = null, ?array $neu = null, $u = null, ?string $text = null): void
    {
        self::t('qm_verlauf')->insert([
            'tabelle'       => $tabelle,
            'datensatz_id'  => (string) $id,
            'aktion'        => mb_substr($aktion, 0, 40),
            'alt'           => $alt === null ? null : json_encode($alt, JSON_UNESCAPED_UNICODE),
            'neu'           => $neu === null ? null : json_encode($neu, JSON_UNESCAPED_UNICODE),
            'text'          => $text,
            'benutzer_id'   => $u->id ?? null,
            'benutzer_name' => $u ? self::userName($u) : 'System',
            'zeit'          => now(),
        ]);
    }

    /** Only the fields that really changed: [alt, neu] or null. */
    public static function diff(object $row, array $patch): ?array
    {
        $alt = $neu = [];
        foreach ($patch as $k => $v) {
            $a = $row->$k ?? null;
            if ((string) ($a ?? '') === (string) ($v ?? '')) continue;
            $alt[$k] = $a;
            $neu[$k] = $v;
        }
        return $neu ? [$alt, $neu] : null;
    }

    public static function verlauf(string $tabelle, $id)
    {
        return self::t('qm_verlauf')->where('tabelle', $tabelle)->where('datensatz_id', (string) $id)
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn ($r) => [
                'aktion' => $r->aktion, 'text' => $r->text, 'zeit' => $r->zeit,
                'von' => $r->benutzer_name,
                'alt' => json_decode($r->alt ?? 'null', true), 'neu' => json_decode($r->neu ?? 'null', true),
            ])->values();
    }

    /** Next number of a yearly range, e.g. R-2026-007 (concurrency-safe). */
    public static function nummer(string $kreis): string
    {
        $jahr = (int) Carbon::now('Europe/Berlin')->year;
        return DB::connection('tenant')->transaction(function () use ($kreis, $jahr) {
            $row = self::t('qm_nummern')->where('kreis', $kreis)->where('jahr', $jahr)->lockForUpdate()->first();
            $n = ($row->zaehler ?? 0) + 1;
            self::t('qm_nummern')->updateOrInsert(['kreis' => $kreis, 'jahr' => $jahr], ['zaehler' => $n]);
            return sprintf('%s-%d-%03d', $kreis, $jahr, $n);
        });
    }

    // ---------------------------------------------------------------
    // People
    // ---------------------------------------------------------------

    public static function userName($u): string
    {
        return trim((string) ($u->full_name ?? $u->name ?? '')) ?: (string) ($u->email ?? 'Benutzer');
    }

    /** Active office users of the tenant: id → name (memoized). */
    public static function userNames(): array
    {
        return self::memo('users', function () {
            $tenant = TenantService::current();
            if (!$tenant) return [];
            return \App\Models\CentralUser::where('tenant_id', $tenant->id)->where('aktiv', 1)
                ->orderBy('full_name')->get(['id', 'full_name', 'name', 'email'])
                ->mapWithKeys(fn ($x) => [(int) $x->id => self::userName($x)])->all();
        });
    }

    public static function workerName(?string $dienstNr): ?string
    {
        if (!$dienstNr) return null;
        $m = self::t('mitarbeiter')->where('dienst_nr', $dienstNr)->first(['vorname', 'name']);
        return $m ? trim($m->vorname . ' ' . $m->name) : $dienstNr;
    }

    // ---------------------------------------------------------------
    // Validation (German messages, no lang files in this app)
    // ---------------------------------------------------------------

    private const ATTR = [
        'obj_id' => 'Objekt', 'text' => 'Text', 'art' => 'Art', 'ort' => 'Ort', 'kategorie' => 'Kategorie',
        'schwere' => 'Schwere', 'kanal' => 'Eingangsweg', 'melder' => 'Gemeldet von', 'eingang_am' => 'Eingang',
        'zustaendig_id' => 'Zuständig', 'dienst_nr' => 'Mitarbeiter', 'sofortmassnahme' => 'Sofortmaßnahme',
        'ursache' => 'Ursache', 'berechtigt' => 'Berechtigt', 'antwort' => 'Antwort', 'kosten_eur' => 'Kosten',
        'titel' => 'Titel', 'frist' => 'Frist', 'verantwortlich_id' => 'Verantwortlich', 'nachweis' => 'Nachweis',
        'grund' => 'Begründung', 'ergebnis' => 'Ergebnis', 'gueltig_ab' => 'Gültig ab', 'kurz' => 'Das Wichtigste',
        'kurz.*' => 'Punkt', 'pdf' => 'PDF', 'pin' => 'PIN', 'pin_alt' => 'Alte PIN', 'kennung' => 'Objekt-Kennung',
        'username' => 'Benutzername', 'password' => 'Passwort', 'name' => 'Name', 'email' => 'E-Mail',
        'objekte' => 'Objekte', 'kunde_id' => 'Kunde', 'bericht_empfaenger' => 'Berichtsempfänger',
        'objektleiter_id' => 'Objektleiter', 'details' => 'Details', 'foto' => 'Foto',
    ];

    /** @return array{0: ?array, 1: array} */
    public static function pruefe(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules, Crm::MSG, self::ATTR);
        return $v->fails() ? [null, $v->errors()->toArray()] : [$v->validated(), []];
    }

    public static function fehler(array $errors, int $status = 422)
    {
        return response()->json(['errors' => $errors, 'error' => collect($errors)->flatten()->first()], $status);
    }

    /** Office lists (private-tenant) + optional live signal for ONE Objekt (worker app, terminal, portal). */
    public static function changed(string $hint = '', ?string $objId = null): void
    {
        self::forget();
        \App\Helpers\Realtime::changed('qm', $hint !== '' ? ['bereich' => $hint] : []);
        if ($objId === null || $objId === '') return;
        try {
            $tenant = TenantService::current();
            if ($tenant) broadcast(new \App\Events\QmObjektChanged((int) $tenant->id, $objId, $hint));
        } catch (\Throwable $e) {
            // best effort — polling / reopening the view stays the fallback
        }
    }
}
