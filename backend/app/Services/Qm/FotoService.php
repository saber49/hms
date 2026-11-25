<?php

namespace App\Services\Qm;

use App\Services\TenantService;
use App\Support\GeoLocation;
use App\Support\Qm\Qm;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Wachbuch photos (spec §6.6): bound to an entry, never deleted, stored on
 * the PRIVATE disk, scaled to max 1600 px, server time + Objekt + guard + GPS
 * burned into the image and kept as data.
 */
class FotoService
{
    public const MAX_PX = 1600;
    public const REGEL = 'required|file|max:15360|mimes:jpg,jpeg,png,webp';

    /** Human-readable reason when PHP/Symfony already marks the upload invalid. */
    public static function uploadFehlermeldung(UploadedFile $file): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Das Foto ist größer als der Server-Upload erlaubt. Bitte erneut aufnehmen oder mit geringerer Auflösung senden.',
            UPLOAD_ERR_PARTIAL => 'Das Foto wurde nicht vollständig übertragen. Bitte erneut versuchen.',
            default => 'Das Foto konnte nicht hochgeladen werden. Bitte erneut versuchen.',
        };
    }

    /** $meta: lat, lng, genauigkeit_m, quelle */
    public static function speichern(object $eintrag, UploadedFile $file, array $meta, ?string $wer): object
    {
        $tenant = TenantService::assertActive();
        $dir = 'qm/' . $tenant->id . '/wachbuch/' . $eintrag->blatt_id;
        $name = Str::random(32) . '.jpg';
        $zeit = Carbon::now();
        try {
            $aufgenommen = !empty($meta['erfasst_am']) ? Carbon::parse($meta['erfasst_am']) : $zeit;
            if ($aufgenommen->gt($zeit)) $aufgenommen = $zeit;
        } catch (\Throwable) {
            $aufgenommen = $zeit;
        }
        $lat = is_numeric($meta['lat'] ?? null) ? round((float) $meta['lat'], 7) : null;
        $lng = is_numeric($meta['lng'] ?? null) ? round((float) $meta['lng'], 7) : null;
        $acc = is_numeric($meta['genauigkeit_m'] ?? null) ? (int) round((float) $meta['genauigkeit_m']) : null;

        $obj = Qm::t('objekte')->where('obj_id', $eintrag->obj_id)->value('objektname') ?? $eintrag->obj_id;

        // Distance to the planned Einsatzort (shift sheet) or Objekt
        // address (office/terminal sheet). It is evidence metadata, not a
        // block: photos may legitimately document something away from it.
        $entfernung = null;
        $nichtAmObjekt = false;
        if ($lat !== null && $lng !== null) {
            $blatt = Qm::t('qm_wb_blaetter')->where('id', $eintrag->blatt_id)->first(['einsatz_id']);
            $ziel = !empty($blatt?->einsatz_id) ? GeoLocation::einsatzAdresse((string) $blatt->einsatz_id) : null;
            $ziel = $ziel ?: GeoLocation::objektAdresse((string) $eintrag->obj_id);
            if ($ziel && ($geo = GeoLocation::geocodeAddress($ziel))) {
                $grenze = max(1, (int) (Qm::objektEinstellung((string) $eintrag->obj_id)->gps_grenze_m ?? 150));
                $entfernung = (int) round(GeoLocation::haversineDistance($lat, $lng, (float) $geo['lat'], (float) $geo['lon']));
                $nichtAmObjekt = $entfernung > $grenze;
            }
        }

        $stempel = $zeit->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') . ' · ' . $obj
            . ($wer ? ' · ' . $wer : '') . ($lat !== null ? sprintf(' · %.5f, %.5f ±%dm', $lat, $lng, $acc ?? 0) : ' · ohne GPS')
            . ($nichtAmObjekt ? ' · nicht am Objekt' : '');

        $bytes = self::verarbeiten($file->getRealPath(), $stempel);
        Storage::disk('local')->put($dir . '/' . $name, $bytes ?? file_get_contents($file->getRealPath()));

        $id = Qm::t('qm_wb_fotos')->insertGetId([
            'eintrag_id' => $eintrag->id, 'pfad' => $dir . '/' . $name, 'aufgenommen_am' => $aufgenommen,
            'lat' => $lat, 'lng' => $lng, 'genauigkeit_m' => $acc,
            'entfernung_objekt_m' => $entfernung, 'nicht_am_objekt' => $nichtAmObjekt,
            'quelle' => in_array($meta['quelle'] ?? '', ['app', 'terminal', 'buero'], true) ? $meta['quelle'] : 'app',
            'created_at' => now(),
        ]);
        Qm::changed('wachbuch', (string) $eintrag->obj_id);
        return Qm::t('qm_wb_fotos')->find($id);
    }

    /** Scale + stamp with GD. Returns JPEG bytes or null when GD cannot read the file. */
    private static function verarbeiten(string $path, string $stempel): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if (!$src) return null;
        if (function_exists('exif_read_data') && ($exif = @exif_read_data($path)) && !empty($exif['Orientation'])) {
            $src = match ((int) $exif['Orientation']) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
        }
        $w = imagesx($src); $h = imagesy($src);
        $f = min(1, self::MAX_PX / max($w, $h));
        $nw = (int) round($w * $f); $nh = (int) round($h * $f);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $bar = 26;
        imagefilledrectangle($dst, 0, $nh - $bar, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 45));
        imagestring($dst, 3, 8, $nh - $bar + 6, self::latin1($stempel), imagecolorallocate($dst, 255, 255, 255));
        ob_start();
        imagejpeg($dst, null, 82);
        return (string) ob_get_clean();
    }

    /** GD's built-in fonts are Latin-1 only. */
    private static function latin1(string $s): string
    {
        return mb_convert_encoding(str_replace(['·', '±'], ['-', '+/-'], $s), 'ISO-8859-1', 'UTF-8');
    }
}
