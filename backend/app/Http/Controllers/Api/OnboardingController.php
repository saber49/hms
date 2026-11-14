<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding after registration + e-mail confirmation: ONE step — the
 * company master data required by German law for invoices, Leistungs-
 * nachweise and the imprint (§ 14 UStG, § 5 DDG, § 35a GmbHG / § 125a HGB):
 * Firma + Rechtsform, Anschrift, Geschäftsführer/Inhaber, Registergericht +
 * Registernummer (registered legal forms) and USt-IdNr. or Steuernummer.
 * Saving the form completes the onboarding. Customers, objects and workers
 * are created later inside the app — they are no longer onboarding steps.
 */
class OnboardingController extends Controller
{
    /** Same list as the "Konto & Firma" form (app.js RECHTSFORMEN). */
    public const RECHTSFORMEN = [
        'GmbH', 'UG (haftungsbeschränkt)', 'AG', 'GmbH & Co. KG',
        'OHG', 'KG', 'GbR', 'e.K.', 'Einzelunternehmen', 'e.V.', 'Genossenschaft (eG)',
    ];

    /** Legal forms entered in a public register (Handels-/Genossenschafts-/Vereinsregister). */
    public const REGISTERPFLICHTIG = [
        'GmbH', 'UG (haftungsbeschränkt)', 'AG', 'GmbH & Co. KG',
        'OHG', 'KG', 'e.K.', 'e.V.', 'Genossenschaft (eG)',
    ];

    private const FIELDS = [
        'name', 'rechtsform', 'geschaeftsfuehrer',
        'company_address', 'company_zip', 'company_city', 'company_country',
        'company_email', 'company_phone', 'website',
        'registergericht', 'handelsregister_nr', 'ust_id', 'steuernummer',
    ];

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'onboarding_completed' => (bool) $tenant->onboarding_completed,
            'can_edit'             => $user->isOwner() || $user->role === 'super_admin',
            'company'              => $tenant->only(self::FIELDS),
            'rechtsformen'         => self::RECHTSFORMEN,
            'registerpflichtig'    => self::REGISTERPFLICHTIG,
        ]);
    }

    /** POST /onboarding/company-profile — saves the legal data and completes the onboarding. */
    public function saveCompanyProfile(Request $request): JsonResponse
    {
        $registered = in_array($request->input('rechtsform'), self::REGISTERPFLICHTIG, true);

        $data = $request->validate([
            'name'               => 'required|string|max:200',
            'rechtsform'         => 'required|string|in:' . implode(',', self::RECHTSFORMEN),
            'geschaeftsfuehrer'  => 'required|string|max:200',
            'company_address'    => 'required|string|max:255',
            'company_zip'        => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\- ]{3,10}$/'],
            'company_city'       => 'required|string|max:100',
            'company_country'    => 'nullable|string|size:2',
            'company_email'      => 'required|email|max:100',
            'company_phone'      => 'nullable|string|max:50',
            'website'            => 'nullable|string|max:255',
            'registergericht'    => ($registered ? 'required' : 'nullable') . '|string|max:100',
            'handelsregister_nr' => ($registered ? 'required' : 'nullable') . '|string|max:50',
            'ust_id'             => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z]{2}[A-Za-z0-9]{2,12}$/', 'required_without:steuernummer'],
            'steuernummer'       => 'nullable|string|max:30|required_without:ust_id',
        ], [
            'name.required'               => 'Bitte den Firmennamen angeben.',
            'rechtsform.required'         => 'Bitte die Rechtsform wählen.',
            'rechtsform.in'               => 'Bitte eine gültige Rechtsform wählen.',
            'geschaeftsfuehrer.required'  => 'Bitte den Geschäftsführer bzw. Inhaber angeben.',
            'company_address.required'    => 'Bitte Straße und Hausnummer angeben.',
            'company_zip.required'        => 'Bitte die Postleitzahl angeben.',
            'company_zip.regex'           => 'Die Postleitzahl ist ungültig.',
            'company_city.required'       => 'Bitte den Ort angeben.',
            'company_email.required'      => 'Bitte eine E-Mail-Adresse der Firma angeben.',
            'company_email.email'         => 'Die E-Mail-Adresse ist ungültig.',
            'registergericht.required'    => 'Für diese Rechtsform ist das Registergericht Pflicht (z. B. Amtsgericht Leipzig).',
            'handelsregister_nr.required' => 'Für diese Rechtsform ist die Registernummer Pflicht (z. B. HRB 12345).',
            'ust_id.regex'                => 'Die USt-IdNr. ist ungültig (Format z. B. DE123456789).',
            'ust_id.required_without'     => 'Bitte USt-IdNr. oder Steuernummer angeben (Pflicht auf Rechnungen, § 14 UStG).',
            'steuernummer.required_without' => 'Bitte USt-IdNr. oder Steuernummer angeben (Pflicht auf Rechnungen, § 14 UStG).',
            '*.max'                       => 'Eine Eingabe ist zu lang.',
        ]);

        $clean = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        if (!empty($clean['ust_id'])) {
            $clean['ust_id'] = strtoupper(str_replace(' ', '', $clean['ust_id']));
        }
        $clean['company_country'] = strtoupper($clean['company_country'] ?? 'DE');
        if (!$registered) {
            // Not in a register → no stale register data on documents.
            $clean['registergericht'] = null;
            $clean['handelsregister_nr'] = null;
        }

        $tenant = $request->user()->tenant;
        $tenant->update($clean + ['onboarding_completed' => true]);

        return response()->json([
            'success' => true,
            'tenant'  => ['name' => $tenant->name, 'slug' => $tenant->slug, 'onboarding_completed' => true],
        ]);
    }
}
