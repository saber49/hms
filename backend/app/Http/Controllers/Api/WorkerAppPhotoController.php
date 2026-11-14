<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Site photos from the worker app chat. The app stamps date, time and place
 * onto the image; the server re-derives the place from the worker's current
 * shift (never trusted from the client) and writes the same data as the
 * chat caption, so the office sees it even without opening the picture.
 */
class WorkerAppPhotoController extends Controller
{
    /**
     * The Objekt the worker is working at, derived from his schedule:
     *   1. a shift running right now (incl. overnight shifts from yesterday),
     *   2. otherwise today's shift closest in time (before/after the shift),
     *   3. otherwise any shift that starts/ended within the last/next 12 h.
     */
    private function currentShift(string $dienstNr): ?object
    {
        $today = now('Europe/Berlin')->toDateString();
        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, o.objektname
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            WHERE em.dienst_nr = ? AND e.datum BETWEEN ? AND ? AND e.pruefung = 'geplant'
        ", [$dienstNr, now('Europe/Berlin')->subDay()->toDateString(), now('Europe/Berlin')->addDay()->toDateString()]);

        $now = now();
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($rows as $r) {
            $w = \App\Support\ShiftWindow::fuer($dienstNr, $r->einsatz_id);
            if (!$w) continue;
            $dist = ($now->gte($w[0]) && $now->lte($w[1])) ? 0
                : min(abs($now->diffInSeconds($w[0], false)), abs($now->diffInSeconds($w[1], false)));
            if ($dist > 0 && substr((string) $r->datum, 0, 10) !== $today && $dist > 12 * 3600) continue;
            // Running shift first, then today's shifts, then the rest — each by distance.
            $score = $dist === 0 ? 0 : ((substr((string) $r->datum, 0, 10) === $today ? 1 : 2) * 100000000 + $dist);
            if ($score < $bestScore) { $bestScore = $score; $best = $r; }
        }
        return $best;
    }

    /** GET /api/worker-app/messages/photo-context — place + server clock for the stamp. */
    public function context(Request $request)
    {
        $shift = $this->currentShift($request->attributes->get('worker_dienst_nr'));
        return response()->json([
            'ort'         => $shift->objektname ?? null,
            'einsatz_id'  => $shift->einsatz_id ?? null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** POST /api/worker-app/messages/photo (multipart: foto, aufgenommen_am) */
    public function store(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $file = $request->file('foto');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'Kein Foto empfangen'], 422);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['error' => 'Foto ist zu groß (max. 10 MB)'], 422);
        }
        $info = @getimagesize($file->getRealPath());
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) {
            return response()->json(['error' => 'Ungültiges Bildformat'], 422);
        }

        // Capture time from the app, but only if it is plausible (±15 min of
        // the server clock) — otherwise the server time is used.
        $taken = null;
        try { $taken = Carbon::parse((string) $request->input('aufgenommen_am')); } catch (\Throwable $e) {}
        if (!$taken || abs(now()->diffInSeconds($taken, false)) > 15 * 60) $taken = now();
        $taken = $taken->copy()->setTimezone('Europe/Berlin');

        $shift = $this->currentShift($dienstNr);
        $ort = $shift->objektname ?? null;

        $tenant = TenantService::current();
        $path = 'chat-fotos/' . ($tenant->slug ?? 'tenant') . '/' . $taken->format('Y/m') . '/' . bin2hex(random_bytes(20)) . '.' . $ext;
        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        $caption = 'Foto · ' . $taken->format('d.m.Y') . ' · ' . $taken->format('H:i') . ' Uhr' . ($ort ? ' · ' . $ort : '');
        $row = [
            'dienst_nr'  => $dienstNr,
            'richtung'   => 'worker',
            'nachricht'  => $caption,
            'datei_pfad' => $path,
            'datei_name' => 'Foto_' . $taken->format('Ymd_Hi') . '.' . $ext,
            'created_at' => now(),
        ];
        // Link the photo to its shift — the Kundenportal shows each customer
        // only the photos of his own Einsätze.
        if ($shift && Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
            $row['einsatz_id'] = $shift->einsatz_id;
        }
        $id = DB::connection('tenant')->table('mitarbeiter_nachrichten')->insertGetId($row);

        return response()->json(['success' => true, 'id' => $id, 'nachricht' => $caption, 'datei_pfad' => $path], 201);
    }
}
