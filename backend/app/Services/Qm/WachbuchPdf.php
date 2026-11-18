<?php

namespace App\Services\Qm;

use App\Services\TenantService;
use App\Support\Qm\Qm;
use App\Support\TenantProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Wachbuch/Objektbuch one-day PDF (office full version + customer version).
 *
 * The customer version applies the same whitelist as the Kundenportal
 * (entry arten + field filter) and is only reachable when the Objekt's
 * "kunde_pdf" setting is on.
 */
class WachbuchPdf
{
    /** Entry arten a customer may see — same as PortalQmController::WB_ARTEN. */
    public const KUNDE_ARTEN = ['dienstbeginn', 'dienstende', 'notiz', 'vorkommnis', 'alarm', 'besucher', 'lieferung', 'schluessel', 'foto', 'hinweis', 'berichtigung'];

    /** Cap embedded photos so a photo-heavy day cannot blow up Dompdf. */
    public const MAX_FOTOS = 20;

    public static function download(string $objId, string $datum, bool $kunde)
    {
        $tenant = TenantService::assertActive();
        $o = Qm::t('objekte as o')->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
            ->where('o.obj_id', $objId)
            ->first(['o.obj_id', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'o.ansprechpartner', 'o.kontakt_telefon', 'k.kundenname']);
        abort_unless($o, 404);

        $blaetter = Qm::t('qm_wb_blaetter')->where('obj_id', $objId)->where('datum', $datum)->orderBy('geoeffnet_am')->get();
        $einsaetze = Qm::t('einsaetze')->whereIn('einsatz_id', $blaetter->pluck('einsatz_id')->filter())->get(['einsatz_id', 'ist_start', 'ist_ende'])->keyBy('einsatz_id');
        $users = Qm::userNames();
        $blattInfos = $blaetter->map(fn ($b) => (object) [
            'id' => (int) $b->id,
            'name' => $b->dienst_nr ? Qm::workerName($b->dienst_nr) : 'Büro',
            'plan' => isset($einsaetze[$b->einsatz_id])
                ? substr((string) $einsaetze[$b->einsatz_id]->ist_start, 0, 5) . '–' . substr((string) $einsaetze[$b->einsatz_id]->ist_ende, 0, 5) : null,
            'geoeffnet_am' => $b->geoeffnet_am, 'geschlossen_am' => $b->geschlossen_am,
            'hinweis_nachfolger' => $b->hinweis_nachfolger,
            'gegengezeichnet_von' => $b->gegengezeichnet_von ? ($users[(int) $b->gegengezeichnet_von] ?? null) : null,
            'gegengezeichnet_am' => $b->gegengezeichnet_am,
            'kette_ok' => WachbuchService::pruefeKette((int) $b->id, $b) === null,
        ]);
        $blattName = $blattInfos->pluck('name', 'id');

        $eintraege = collect(WachbuchService::eintraege($blaetter->pluck('id')->all()));
        $nrMap = $eintraege->pluck('nr', 'id');
        if ($kunde) $eintraege = $eintraege->whereIn('art', self::KUNDE_ARTEN);
        $eintraege = $eintraege->map(function ($e) use ($kunde, $blattName, $nrMap) {
            $details = is_array($e['details']) ? $e['details'] : (array) $e['details'];
            if ($kunde) $details = self::kundenDetails($e['art'], $details);
            $e['details_text'] = self::detailsText($e['art'], $details);
            $e['blatt_name'] = $blattName[$e['blatt_id']] ?? '';
            $e['ref_nr'] = $e['ref_id'] ? ($nrMap[$e['ref_id']] ?? null) : null;
            return $e;
        })->values();

        // Photos as data URIs (capped); count overflow for a footer note.
        $ids = $eintraege->pluck('id')->all();
        $fotoRows = $ids ? Qm::t('qm_wb_fotos')->whereIn('eintrag_id', $ids)->orderBy('id')->get() : collect();
        $gesamtFotos = $fotoRows->count();
        $fotos = [];
        $eingebettet = 0;
        foreach ($fotoRows as $f) {
            if ($eingebettet >= self::MAX_FOTOS) break;
            $uri = self::dataUri($f->pfad);
            if (!$uri) continue;
            $eingebettet++;
            $fotos[$f->eintrag_id][] = [
                'uri' => $uri,
                'cap' => Carbon::parse($f->aufgenommen_am)->setTimezone('Europe/Berlin')->format('H:i')
                    . ($f->lat !== null ? sprintf(' · %.5f, %.5f', (float) $f->lat, (float) $f->lng) : ' · ohne GPS')
                    . ($f->nicht_am_objekt ? ' · nicht am Objekt' : ''),
            ];
        }

        $logo = null;
        if (!empty($tenant->logo_path)) {
            $full = storage_path('app/public/' . $tenant->logo_path);
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (is_file($full) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
                $logo = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode(file_get_contents($full));
            }
        }

        $label = TenantProfile::objektbuchLabel($tenant);
        $pdf = Pdf::loadView('pdf.wachbuch', [
            'firma' => $tenant->name ?? '',
            'firmaInfo' => trim(implode(' | ', array_filter([
                trim(($tenant->company_address ?? '') . ', ' . trim(($tenant->company_zip ?? '') . ' ' . ($tenant->company_city ?? '')), ', '),
                !empty($tenant->company_phone) ? 'Tel: ' . $tenant->company_phone : '',
                $tenant->company_email ?? '',
            ]))),
            'logo' => $logo,
            'label' => $label,
            'kunde' => $kunde,
            'objekt' => $o,
            'datum' => $datum,
            'tagBeginn' => Qm::tagBeginn(),
            'blaetter' => $blattInfos,
            'eintraege' => $eintraege,
            'fotos' => $fotos,
            'fotosNichtGezeigt' => max(0, $gesamtFotos - $eingebettet),
            'erstellt' => Carbon::now('Europe/Berlin')->format('d.m.Y H:i'),
        ])->setPaper('A4', 'portrait');

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label . '_' . ($o->objektname ?: $objId)) . '_' . $datum . '.pdf';
        return $pdf->download($name);
    }

    private static function dataUri(?string $path): ?string
    {
        if (!$path || !Storage::disk('local')->exists($path)) return null;
        return 'data:image/jpeg;base64,' . base64_encode(Storage::disk('local')->get($path));
    }

    /** Same field whitelist as PortalQmController::kundenDetails. */
    private static function kundenDetails(string $art, array $d): array
    {
        return match ($art) {
            'besucher'   => array_intersect_key($d, array_flip(['name', 'firma', 'ziel', 'angemeldet', 'gegangen'])),
            'lieferung'  => array_intersect_key($d, array_flip(['firma', 'zweck'])),
            'schluessel' => array_intersect_key($d, array_flip(['schluessel', 'aktion'])),
            'vorkommnis', 'alarm' => array_intersect_key($d, array_flip(['massnahmen', 'benachrichtigt'])),
            default      => [],
        };
    }

    private static function detailsText(string $art, array $d): string
    {
        $has = fn ($k) => trim((string) ($d[$k] ?? '')) !== '' ? $d[$k] : null;
        $ja = fn ($v) => $v ? 'ja' : 'nein';
        $notify = collect($d['benachrichtigt'] ?? [])->map(fn ($b) => trim(($b['stelle'] ?? '') . ' ' . ($b['zeit'] ?? '')))->filter()->implode(', ');
        $freigabe = ['objektleitung' => 'Objektleitung', 'auftraggeber' => 'Auftraggeber', 'haustechnik' => 'Haustechnik', 'empfang' => 'Empfang', 'liste_kunde' => 'Liste Kunde', 'ohne' => 'ohne'];
        $parts = match ($art) {
            'besucher' => [
                $has('name') ? 'Name: ' . $d['name'] : null,
                $has('firma') ? 'Firma: ' . $d['firma'] : null,
                $has('ziel') ? 'Ziel: ' . $d['ziel'] : null,
                $has('kennzeichen') ? 'Kennz.: ' . $d['kennzeichen'] : null,
                isset($d['angemeldet']) ? 'angemeldet: ' . $ja($d['angemeldet']) : null,
                isset($d['ausweis_geprueft']) ? 'Ausweis geprüft: ' . $ja($d['ausweis_geprueft']) : null,
                $has('gegangen') ? 'gegangen ' . $d['gegangen'] : null,
            ],
            'lieferung' => [
                $has('firma') ? 'Firma: ' . $d['firma'] : null,
                $has('person') ? 'Person: ' . $d['person'] : null,
                $has('kennzeichen') ? 'Kennz.: ' . $d['kennzeichen'] : null,
                $has('zweck') ? 'Zweck: ' . $d['zweck'] : null,
                $has('freigabe_durch') ? 'Freigabe: ' . ($freigabe[$d['freigabe_durch']] ?? $d['freigabe_durch']) : null,
                isset($d['ausweis_geprueft']) ? 'Ausweis geprüft: ' . $ja($d['ausweis_geprueft']) : null,
            ],
            'schluessel' => [
                $has('schluessel') ? 'Schlüssel: ' . $d['schluessel'] : null,
                $has('aktion') ? $d['aktion'] : null,
                $has('an') ? 'an ' . $d['an'] : null,
            ],
            'vorkommnis', 'alarm' => [
                $has('massnahmen') ? 'Maßnahmen: ' . $d['massnahmen'] : null,
                $notify !== '' ? 'Benachrichtigt: ' . $notify : null,
            ],
            'uebergabe' => [
                $has('von') ? 'von ' . $d['von'] : null,
            ],
            default => [],
        };
        return implode(' · ', array_filter($parts));
    }
}
