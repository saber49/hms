<?php

namespace App\Services\Qm;

use App\Services\TenantService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dienstanweisung am Objekt (spec §4, §6.7).
 *
 * entwurf ──QMB frees──▶ freigegeben (version N, current) ──newer version──▶ archiv
 *        └─QMB rejects─▶ zurueckgewiesen (reason)
 *
 * Without a confirmation of the CURRENT version a worker cannot check in at
 * that Objekt; "Gelesen und verstanden" needs the full PDF opened first.
 */
class DienstanweisungService
{
    public const MAX_KB = 20480;
    public const DISK = 'local';

    /** Create a draft (or publish at once when no release is needed). */
    public static function erstellen($u, string $objId, UploadedFile $pdf, string $gueltigAb, array $kurz): object
    {
        $tenant = TenantService::assertActive();
        $token = Str::random(48);
        $dir = 'qm/' . $tenant->id . '/da/' . $objId;
        $pdf->storeAs($dir, $token . '.pdf', self::DISK);
        $path = $dir . '/' . $token . '.pdf';

        $id = Qm::t('qm_dienstanweisungen')->insertGetId([
            'obj_id' => $objId, 'status' => 'entwurf', 'gueltig_ab' => $gueltigAb,
            'kurz' => json_encode(array_values($kurz), JSON_UNESCAPED_UNICODE),
            'pdf_pfad' => $path, 'pdf_name' => mb_substr($pdf->getClientOriginalName(), 0, 250),
            'seiten' => self::seiten(Storage::disk(self::DISK)->path($path)),
            'token' => $token, 'erstellt_von' => $u->id ?? null, 'erstellt_von_name' => Qm::userName($u),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Qm::log('dienstanweisung', $id, 'angelegt', null, ['obj_id' => $objId, 'gueltig_ab' => $gueltigAb], $u);
        $da = Qm::t('qm_dienstanweisungen')->find($id);

        if (!Qm::boolSetting('da_freigabe_pflicht') || QmRechte::darf($u, 'da_freigeben')) {
            return self::veroeffentlichen($da, $u);
        }
        $obj = Qm::t('objekte')->where('obj_id', $objId)->value('objektname') ?? $objId;
        QmNotify::users(QmNotify::rollenUser(['qmb'], 'da_freigeben'), 'dienstanweisung.draft',
            'Dienstanweisung zur Freigabe: ' . $obj, Qm::userName($u) . ' hat einen Entwurf hochgeladen.',
            '#qm?view=objekte', 'dienstanweisung', $id);
        Qm::changed('dienstanweisung', $objId);
        return $da;
    }

    public static function veroeffentlichen(object $da, $u): object
    {
        DB::connection('tenant')->transaction(function () use ($da, $u) {
            $max = (int) Qm::t('qm_dienstanweisungen')->where('obj_id', $da->obj_id)->lockForUpdate()->max('version');
            Qm::t('qm_dienstanweisungen')->where('obj_id', $da->obj_id)->where('status', 'freigegeben')
                ->update(['status' => 'archiv', 'updated_at' => now()]);
            Qm::t('qm_dienstanweisungen')->where('id', $da->id)->update([
                'status' => 'freigegeben', 'version' => $max + 1,
                'freigegeben_von' => $u->id ?? null, 'freigegeben_am' => now(),
                'pruefung_faellig_am' => Carbon::parse($da->gueltig_ab)->addMonths(max(1, Qm::intSetting('da_pruefung_monate', 12)))->toDateString(),
                'updated_at' => now(),
            ]);
        });
        $da = Qm::t('qm_dienstanweisungen')->find($da->id);
        Qm::log('dienstanweisung', $da->id, 'freigegeben', null, ['version' => $da->version], $u);
        self::verteilen($da);
        Qm::changed('dienstanweisung', (string) $da->obj_id);
        return $da;
    }

    public static function zurueckweisen(object $da, string $grund, $u): void
    {
        Qm::t('qm_dienstanweisungen')->where('id', $da->id)->update([
            'status' => 'zurueckgewiesen', 'zurueckgewiesen_grund' => $grund, 'updated_at' => now(),
        ]);
        Qm::log('dienstanweisung', $da->id, 'zurueckgewiesen', null, ['grund' => $grund], $u);
        if ($da->erstellt_von) {
            QmNotify::users([(int) $da->erstellt_von], 'dienstanweisung.rejected', 'Dienstanweisung zurückgewiesen', $grund, '#qm?view=objekte', 'dienstanweisung', $da->id);
        }
        Qm::changed('dienstanweisung');
    }

    /** Push to planned / recent workers, mail + portal hint to the customer. */
    public static function verteilen(object $da): void
    {
        $obj = Qm::t('objekte')->where('obj_id', $da->obj_id)->first(['objektname', 'kunden_id']);
        $name = $obj->objektname ?? $da->obj_id;
        foreach (self::mitarbeiterDesObjekts($da->obj_id) as $dn) {
            QmNotify::worker($dn, 'dienstanweisung', 'Neue Dienstanweisung: ' . $name,
                'Version ' . $da->version . ' — bitte vor dem nächsten Dienst lesen und bestätigen.',
                ['da_id' => (int) $da->id], 'dienstanweisung', $da->id);
        }
        if ($obj && $obj->kunden_id) {
            $mails = Qm::t('kunden_portal_zugaenge')->where('kunde_id', $obj->kunden_id)->where('aktiv', 1)->get()
                ->filter(fn ($z) => \App\Services\Qm\PortalService::zugangSiehtObjekt($z, $da->obj_id))
                ->pluck('email')->filter()->all();
            QmNotify::mail($mails, 'dienstanweisung.published', 'Neue Dienstanweisung für ' . $name,
                "Für Ihr Objekt $name wurde die Dienstanweisung Version {$da->version} veröffentlicht. "
                . 'Sie können sie im Kundenportal einsehen und bestätigen.', 'kunde', $obj->kunden_id, 'dienstanweisung', $da->id);
        }
    }

    /** Workers planned for the Objekt today … +30 days or clocked in there in the last 90 days. */
    public static function mitarbeiterDesObjekts(string $objId): array
    {
        return self::mitarbeiterJeObjekt([$objId])[$objId] ?? [];
    }

    /** Same for many objects in two queries: obj_id => [dienst_nr]. */
    public static function mitarbeiterJeObjekt(array $objIds): array
    {
        if (!$objIds) return [];
        $heute = Qm::heute();
        $geplant = Qm::t('einsatz_mitarbeiter as em')->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->whereIn('e.obj_id', $objIds)->whereBetween('e.datum', [$heute, Carbon::parse($heute)->addDays(30)->toDateString()])
            ->distinct()->get(['e.obj_id', 'em.dienst_nr as dn']);
        $vorher = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->whereIn('e.obj_id', $objIds)->where('z.clock_in', '>=', now()->subDays(90))
            ->distinct()->get(['e.obj_id', 'z.dienst_nr as dn']);
        return $geplant->merge($vorher)->filter(fn ($r) => $r->dn)->groupBy('obj_id')
            ->map(fn ($g) => $g->pluck('dn')->map(fn ($x) => (string) $x)->unique()->values()->all())->all();
    }

    // ---------------------------------------------------------------
    // Reading + confirmation
    // ---------------------------------------------------------------

    public static function aktuelle(string $objId): ?object
    {
        return Qm::t('qm_dienstanweisungen')->where('obj_id', $objId)->where('status', 'freigegeben')
            ->orderByDesc('version')->first();
    }

    /** Current DA of the Objekt the worker has not confirmed yet (check-in gate), or null. */
    public static function sperre(string $dienstNr, string $objId): ?object
    {
        if (!Qm::ready() || !Qm::boolSetting('modul_qm')) return null;
        $da = self::aktuelle($objId);
        if (!$da) return null;
        $ok = Qm::t('qm_da_bestaetigungen')->where('dienstanweisung_id', $da->id)
            ->where('dienst_nr', $dienstNr)->whereNotNull('bestaetigt_am')->exists();
        return $ok ? null : $da;
    }

    /** Current DAs of every Objekt the worker works at, with his confirmation state. */
    public static function fuerMitarbeiter(string $dienstNr): array
    {
        $heute = Qm::heute();
        $objIds = Qm::t('einsatz_mitarbeiter as em')->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->where('em.dienst_nr', $dienstNr)
            ->whereBetween('e.datum', [Carbon::parse($heute)->subDays(90)->toDateString(), Carbon::parse($heute)->addDays(30)->toDateString()])
            ->distinct()->pluck('e.obj_id')->filter()->all();
        if (!$objIds) return [];
        $das = Qm::t('qm_dienstanweisungen as d')->join('objekte as o', 'o.obj_id', '=', 'd.obj_id')
            ->whereIn('d.obj_id', $objIds)->where('d.status', 'freigegeben')
            ->orderBy('o.objektname')->orderByDesc('d.version')
            ->get(['d.*', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt'])->unique('obj_id');
        $best = Qm::t('qm_da_bestaetigungen')->where('dienst_nr', $dienstNr)->whereIn('dienstanweisung_id', $das->pluck('id'))
            ->get()->keyBy('dienstanweisung_id');
        return $das->map(fn ($d) => self::format($d) + [
            'objektname' => $d->objektname,
            'adresse' => trim(($d->adresse ?? '') . ', ' . trim(($d->plz ?? '') . ' ' . ($d->stadt ?? '')), ', '),
            'pdf_geoeffnet_am' => $best[$d->id]->pdf_geoeffnet_am ?? null,
            'bestaetigt_am' => $best[$d->id]->bestaetigt_am ?? null,
        ])->values()->all();
    }

    public static function geoeffnet(object $da, string $dienstNr): void
    {
        $row = Qm::t('qm_da_bestaetigungen')->where('dienstanweisung_id', $da->id)->where('dienst_nr', $dienstNr)->first();
        if ($row) {
            if (!$row->pdf_geoeffnet_am) Qm::t('qm_da_bestaetigungen')->where('id', $row->id)->update(['pdf_geoeffnet_am' => now(), 'updated_at' => now()]);
            return;
        }
        Qm::t('qm_da_bestaetigungen')->insertOrIgnore([
            'dienstanweisung_id' => $da->id, 'dienst_nr' => $dienstNr, 'pdf_geoeffnet_am' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return string|null error */
    public static function bestaetigen(object $da, string $dienstNr, string $quelle): ?string
    {
        $row = Qm::t('qm_da_bestaetigungen')->where('dienstanweisung_id', $da->id)->where('dienst_nr', $dienstNr)->first();
        if (!$row || !$row->pdf_geoeffnet_am) return 'Bitte zuerst die vollständige Dienstanweisung öffnen.';
        if ($row->bestaetigt_am) return null;
        Qm::t('qm_da_bestaetigungen')->where('id', $row->id)->update(['bestaetigt_am' => now(), 'quelle' => $quelle, 'updated_at' => now()]);
        Qm::log('dienstanweisung', $da->id, 'mitarbeiter_bestaetigt', null, ['dienst_nr' => $dienstNr, 'quelle' => $quelle], null, Qm::workerName($dienstNr));
        Qm::changed('dienstanweisung', (string) $da->obj_id);
        return null;
    }

    public static function format(object $d): array
    {
        return [
            'id' => (int) $d->id, 'obj_id' => $d->obj_id, 'version' => $d->version ? (int) $d->version : null,
            'status' => $d->status, 'gueltig_ab' => substr((string) $d->gueltig_ab, 0, 10),
            'kurz' => json_decode($d->kurz ?? '[]', true) ?: [], 'seiten' => (int) $d->seiten, 'pdf_name' => $d->pdf_name,
            'erstellt_von' => $d->erstellt_von_name, 'created_at' => $d->created_at,
            'freigegeben_am' => $d->freigegeben_am, 'zurueckgewiesen_grund' => $d->zurueckgewiesen_grund,
            'pruefung_faellig_am' => $d->pruefung_faellig_am,
        ];
    }

    public static function pdfResponse(object $da)
    {
        $disk = Storage::disk(self::DISK);
        if (!$disk->exists($da->pdf_pfad)) abort(404);
        return response()->file($disk->path($da->pdf_pfad), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Dienstanweisung_v' . ($da->version ?: 'Entwurf') . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Page count from the PDF structure (no extra library needed). */
    public static function seiten(string $file): int
    {
        $raw = @file_get_contents($file) ?: '';
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $raw, $m)) return max(array_map('intval', $m[1]));
        return max(1, preg_match_all('#/Type\s*/Page\b(?!s)#', $raw));
    }
}
