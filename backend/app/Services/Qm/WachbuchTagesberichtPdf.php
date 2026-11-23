<?php

namespace App\Services\Qm;

use App\Services\TenantService;
use App\Support\TenantProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class WachbuchTagesberichtPdf
{
    public static function download(string $objId, string $datum, bool $kunde, ?object $version = null)
    {
        $tenant = TenantService::assertActive();
        if ($kunde && !$version) abort(404);
        $daten = $version ? WachbuchTagesbericht::snapshot($version) : WachbuchTagesbericht::entwurf($objId, $datum);
        if ($daten['obj_id'] !== $objId || $daten['datum'] !== $datum) abort(404);
        if ($kunde) $daten = WachbuchTagesbericht::kundenSicht($daten);

        $settings = WachbuchPdf::pdfSettings();
        $company = [
            'address' => $tenant->company_address ?? '', 'zip' => $tenant->company_zip ?? '',
            'city' => $tenant->company_city ?? '', 'phone' => $tenant->company_phone ?? '',
            'email' => $tenant->company_email ?? '', 'website' => $tenant->website ?? '',
            'register' => $tenant->handelsregister_nr ?? '', 'ust_id' => $tenant->ust_id ?? '',
            'bank' => $tenant->bank_name ?? '', 'iban' => $tenant->iban ?? '',
        ];
        $logo = null;
        if (empty($settings['hide_logo']) && !empty($tenant->logo_path)) {
            $full = storage_path('app/public/' . $tenant->logo_path);
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (is_file($full) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
                $logo = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode(file_get_contents($full));
            }
        }

        $fotos = [];
        $fotoIds = collect($daten['eintraege'])->pluck('id')->all();
        $rows = $fotoIds ? \App\Support\Qm\Qm::t('qm_wb_fotos')->whereIn('eintrag_id', $fotoIds)->orderBy('id')->get() : collect();
        $gezeigt = 0;
        foreach ($rows as $f) {
            if ($gezeigt >= WachbuchPdf::MAX_FOTOS) break;
            if (!Storage::disk('local')->exists($f->pfad)) continue;
            $fotos[$f->eintrag_id][] = 'data:image/jpeg;base64,' . base64_encode(Storage::disk('local')->get($f->pfad));
            $gezeigt++;
        }

        $label = TenantProfile::objektbuchLabel($tenant);
        $pdf = Pdf::loadView('pdf.wachbuch-tagesbericht', [
            'daten' => $daten, 'kunde' => $kunde, 'version' => $version,
            'label' => $label, 'logo' => $logo, 'settings' => $settings, 'company' => $company,
            'fotos' => $fotos, 'fotosNichtGezeigt' => max(0, $rows->count() - $gezeigt),
        ])->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(476, 818, 'Seite {PAGE_NUM} von {PAGE_COUNT}', $font, 7, [0.40, 0.45, 0.50]);

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label . '_' . $daten['objektname'])
            . '_' . $datum . ($version ? '_v' . $version->version : '_Entwurf') . '.pdf';
        return $pdf->download($name);
    }
}
