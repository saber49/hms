<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Worker e-signatures on documents, requested from the office and signed in
 * the worker app.
 *
 * The worker never receives the document itself: he gets a summary of HIS
 * OWN rows (where, which days, which hours) — the "snapshot". His signature
 * is stored once per request and is valid for exactly the rows he saw; a row
 * that changes afterwards loses the signature until he signs again.
 *
 * New document types plug in via SNAPSHOT_BUILDERS (type → method returning
 * the per-worker snapshots); controllers, table and app screens are generic.
 * Everything lives in the tenant DB / under the tenant's own storage folder.
 */
class DokumentSignaturService
{
    public const TABLE = 'dokument_signaturen';

    /** doc_type → [label, snapshot builder method]. */
    private const SNAPSHOT_BUILDERS = [
        'order' => ['Leistungsnachweis', 'orderSnapshots'],
    ];

    private const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const DATA_URL_RE = '~^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$~';
    private const MAX_IMAGE_BYTES = 400 * 1024;

    public static function ready(): bool
    {
        return Schema::connection('tenant')->hasTable(self::TABLE);
    }

    public static function supports(string $type): bool
    {
        return isset(self::SNAPSHOT_BUILDERS[$type]);
    }

    public static function label(string $type): string
    {
        return self::SNAPSHOT_BUILDERS[$type][0] ?? 'Dokument';
    }

    /**
     * Current content of a document per internal worker:
     * [dienst_nr => ['titel' => string, 'daten' => array]]. `daten.rows` =
     * [['key', 'datum', 'von', 'bis', 'pause', 'std'], …] — `key` identifies
     * the row inside the document (order: einsatz_id).
     */
    public static function workerSnapshots(string $type, string $ref): array
    {
        TenantService::assertActive();
        if (!self::supports($type)) return [];
        return self::{self::SNAPSHOT_BUILDERS[$type][1]}($ref);
    }

    /** Leistungsnachweis: the customer-approved rows of the order, per worker. */
    private static function orderSnapshots(string $bestellId): array
    {
        $order = DB::connection('tenant')->table('bestellungen as b')
            ->leftJoin('objekte as o', 'b.obj_id', '=', 'o.obj_id')
            ->where('b.bestell_id', $bestellId)
            ->first(['b.bestell_id', 'o.objektname']);
        if (!$order) return [];

        $byWorker = [];
        foreach (AbrechnungDataService::confirmedOrderAssignments($bestellId)['workers'] as $w) {
            $pause = max(0, (int) ($w->pause_minuten ?? 0));
            // kundenstunden already excludes the customer pause.
            $std = round(max(0, (float) ($w->kundenstunden ?? 0)), 2);
            $byWorker[(string) $w->dienst_nr]['name'] = trim(($w->vorname ?? '') . ' ' . ($w->name ?? ''));
            $byWorker[(string) $w->dienst_nr]['rows'][] = [
                'key'    => (string) $w->einsatz_id,
                'datum'  => substr((string) $w->datum, 0, 10),
                'von'    => substr((string) ($w->ist_start ?? ''), 0, 5),
                'bis'    => substr((string) ($w->ist_ende ?? ''), 0, 5),
                'pause'  => $pause,
                'std'    => $std,
                'objekt' => (string) ($w->objektname ?? ''),
            ];
        }

        $firma = (string) (TenantService::current()->name ?? '');
        $out = [];
        foreach ($byWorker as $dienstNr => $w) {
            $rows = $w['rows'];
            usort($rows, fn ($a, $b) => [$a['datum'], $a['von'], $a['key']] <=> [$b['datum'], $b['von'], $b['key']]);
            $objekt = (string) ($order->objektname ?: ($rows[0]['objekt'] ?? ''));
            $zeitraum = self::zeitraum(array_column($rows, 'datum'));
            $out[$dienstNr] = [
                'titel' => mb_substr(trim('Leistungsnachweis · ' . $objekt . ' · ' . $zeitraum, ' ·'), 0, 190),
                'daten' => [
                    'dokument'    => 'Leistungsnachweis',
                    'nr'          => (string) $order->bestell_id,
                    'firma'       => $firma,
                    'objekt'      => $objekt,
                    'zeitraum'    => $zeitraum,
                    'mitarbeiter' => $w['name'],
                    'rows'        => $rows,
                    'einsaetze'   => count($rows),
                    'tage'        => count(array_unique(array_column($rows, 'datum'))),
                    'std'         => round(array_sum(array_column($rows, 'std')), 2),
                    'pause'       => array_sum(array_column($rows, 'pause')),
                ],
            ];
        }
        return $out;
    }

    private static function zeitraum(array $dates): string
    {
        $dates = array_values(array_filter($dates));
        if (!$dates) return '';
        sort($dates);
        [$a, $b] = [$dates[0], end($dates)];
        if (substr($a, 0, 7) === substr($b, 0, 7)) {
            return (self::MONTHS[(int) substr($a, 5, 2)] ?? '') . ' ' . substr($a, 0, 4);
        }
        $de = fn (string $d) => substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4);
        return $de($a) . ' – ' . $de($b);
    }

    /** Identity of what a worker is asked to confirm (his rows only). */
    public static function hash(array $daten): string
    {
        return hash('sha256', json_encode(array_map([self::class, 'rowIdentity'], $daten['rows'] ?? []), JSON_UNESCAPED_UNICODE));
    }

    private static function rowIdentity(array $r): array
    {
        return [(string) ($r['key'] ?? ''), (string) ($r['datum'] ?? ''), (string) ($r['von'] ?? ''), (string) ($r['bis'] ?? ''),
            (int) ($r['pause'] ?? 0), number_format((float) ($r['std'] ?? 0), 2, '.', '')];
    }

    /** Keys of the current rows that are identical to a row of the signed snapshot. */
    public static function validKeys(array $signedDaten, array $currentDaten): array
    {
        $signed = [];
        foreach ($signedDaten['rows'] ?? [] as $r) $signed[(string) ($r['key'] ?? '')] = self::rowIdentity($r);
        $keys = [];
        foreach ($currentDaten['rows'] ?? [] as $r) {
            $k = (string) ($r['key'] ?? '');
            if (isset($signed[$k]) && $signed[$k] === self::rowIdentity($r)) $keys[] = $k;
        }
        return $keys;
    }

    /** Decoded PNG of a signature data URL, or null when it is not a sane image. */
    public static function decodeSignature($dataUrl): ?string
    {
        if (!is_string($dataUrl) || strlen($dataUrl) > self::MAX_IMAGE_BYTES * 1.4 || !preg_match(self::DATA_URL_RE, $dataUrl, $m)) return null;
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 100 || strlen($bin) > self::MAX_IMAGE_BYTES) return null;
        if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) return null;
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 40 || $info[1] < 20 || $info[0] > 2400 || $info[1] > 1600) return null;
        return $bin;
    }

    public static function storeSignature(int $tenantId, int $id, string $png): string
    {
        $path = 'dokument_signaturen/' . $tenantId . '/' . $id . '_' . bin2hex(random_bytes(8)) . '.png';
        Storage::disk('local')->put($path, $png);
        return $path;
    }

    /** Signature image as data URL — only from the tenant's own folder. */
    public static function signatureDataUrl(int $tenantId, ?string $path): ?string
    {
        if (!$path || !str_starts_with($path, 'dokument_signaturen/' . $tenantId . '/') || str_contains($path, '..')) return null;
        $disk = Storage::disk('local');
        if (!$disk->exists($path)) return null;
        return 'data:image/png;base64,' . base64_encode((string) $disk->get($path));
    }

    public static function decodeDaten($json): array
    {
        $d = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($d)) return [];
        // The customer name is not shown to workers (also not from requests stored before 2026-11-11).
        unset($d['kunde']);
        return $d;
    }
}
