<?php

namespace App\Http\Controllers\Api\Qm\Einstellungen;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use Illuminate\Http\Request;

/**
 * QM-Einstellungen (spec §6.9): every setting phrased as a question with a
 * simple choice, saved at once and logged. Only the QMB may change them.
 */
class EinstellungenController extends Controller
{
    use QmGuards;

    /** Allowed values per setting (simple choices instead of free numbers). */
    private const WERTE = [
        'modul_wachbuch'       => ['0', '1'],
        'modul_qm'             => ['0', '1'],
        'da_freigabe_pflicht'  => ['0', '1'],
        'da_pruefung_monate'   => ['6', '12', '24'],
        'rek_bestaetigung_std' => ['4', '8', '24', '48'],
        'rek_frist_gering'     => ['7', '14', '30'],
        'rek_frist_mittel'     => ['3', '5', '7', '14'],
        'rek_frist_hoch'       => ['1', '2', '3', '5'],
        'rek_frist_kritisch'   => ['1', '2'],
        'mn_wirksam_tage'      => ['14', '30', '60', '90'],
        'mn_max_verlaengerung' => ['1', '2', '3'],
        'terminal_sitzung_std' => ['8', '12', '24'],
        'terminal_max_fehl'    => ['3', '5', '10'],
        'pin_max_fehl'         => ['3', '5'],
    ];

    /** GET /api/qm/einstellungen */
    public function show(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        return response()->json([
            'werte' => array_intersect_key(Qm::settings(), self::WERTE + ['absender_email' => 1]),
            'auswahl' => self::WERTE,
            'rechte' => QmRechte::tabelle(),
            'rollen' => QmRechte::ROLLEN_LABEL,
            'darf_aendern' => QmRechte::darf($request->user(), 'einstellungen'),
            'verlauf' => Qm::t('qm_verlauf')->where('tabelle', 'einstellungen')->orderByDesc('id')->limit(50)
                ->get(['datensatz_id', 'alt', 'neu', 'benutzer_name', 'zeit']),
        ]);
    }

    /** PUT /api/qm/einstellungen {key: value, …} */
    public function update(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'einstellungen')) return $e;
        $errors = [];
        foreach ($request->all() as $k => $v) {
            if ($k === 'absender_email') {
                $v = trim((string) $v);
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[$k][] = 'Ungültige E-Mail-Adresse.'; continue; }
                Qm::setSetting($k, $v, $u);
                continue;
            }
            if (!isset(self::WERTE[$k])) continue;
            $v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            if (!in_array($v, self::WERTE[$k], true)) { $errors[$k][] = 'Ungültiger Wert.'; continue; }
            Qm::setSetting($k, $v, $u);
        }
        if ($errors) return Qm::fehler($errors);
        Qm::changed('einstellungen');
        return $this->show($request);
    }

    /** PUT /api/qm/einstellungen/rechte {aktion: [rollen]} */
    public function rechte(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'einstellungen')) return $e;
        $neu = [];
        foreach (QmRechte::STANDARD as $k => [$_, $std]) {
            $in = $request->input($k);
            $neu[$k] = is_array($in) ? array_values(array_intersect(array_keys(QmRechte::ROLLEN), $in)) : QmRechte::tabelle()[$k]['rollen'];
        }
        $neu['einstellungen'] = ['qmb'];
        Qm::setSetting('rechte', json_encode($neu), $u);
        Qm::changed('einstellungen');
        return $this->show($request);
    }
}
