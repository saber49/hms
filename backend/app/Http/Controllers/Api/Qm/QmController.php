<?php

namespace App\Http\Controllers\Api\Qm;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\MassnahmeService;
use App\Services\Qm\ReklamationService;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use App\Support\Qm\QmScope;
use App\Support\TenantProfile;
use App\Services\TenantService;
use Illuminate\Http\Request;

/** Module bootstrap (rights, lists, objects) + Übersicht ("Heute zu tun"). */
class QmController extends Controller
{
    use QmGuards;

    /** GET /api/qm/optionen */
    public function optionen(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        return response()->json([
            'heute'    => Qm::heute(),
            'ich'      => ['id' => (int) $u->id, 'rollen' => QmRechte::rollen($u), 'rechte' => QmRechte::fuer($u), 'alle_objekte' => QmScope::alleObjekte($u)],
            'label'    => TenantProfile::objektbuchLabel(TenantService::current()),
            'objekte'  => QmScope::objekte($u)->map(fn ($o) => [
                'obj_id' => $o->obj_id, 'objektname' => $o->objektname, 'kundenname' => $o->kundenname,
                'kunden_id' => $o->kunden_id, 'wachbuch_aktiv' => (bool) $o->wachbuch_aktiv,
                'adresse' => trim(($o->adresse ?? '') . ', ' . trim(($o->plz ?? '') . ' ' . ($o->stadt ?? '')), ', '),
            ])->values(),
            'benutzer' => collect(Qm::userNames())->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values(),
            'listen'   => [
                'wb_arten' => WachbuchService::ARTEN, 'freigabe_durch' => WachbuchService::FREIGABE_DURCH,
                'rek_status' => ReklamationService::STATUS, 'rek_schwere' => ReklamationService::SCHWERE,
                'rek_kanal' => ReklamationService::KANAL, 'rek_kategorien' => ReklamationService::KATEGORIEN,
                'rek_schritte' => ReklamationService::SCHRITTE,
                'mn_quellen' => MassnahmeService::QUELLEN, 'mn_arten' => MassnahmeService::ARTEN, 'mn_status' => MassnahmeService::STATUS,
            ],
            'einstellungen' => array_intersect_key(Qm::settings(), array_flip(['modul_wachbuch', 'modul_qm', 'da_freigabe_pflicht', 'mn_max_verlaengerung'])),
        ]);
    }

    /** GET /api/qm/uebersicht — KPI tiles + "Heute zu tun" across all areas (own objects only). */
    public function uebersicht(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $heute = Qm::heute();

        $rek = QmScope::apply(Qm::t('qm_reklamationen as r')->join('objekte as o', 'o.obj_id', '=', 'r.obj_id'), $u, 'r.obj_id')
            ->where('r.status', '!=', 'abgeschlossen')
            ->selectRaw('COUNT(*) AS offen,
                SUM(r.bestaetigt_am IS NULL AND r.frist_bestaetigung < ?) AS best_ueber,
                SUM(r.frist_erledigung < ?) AS ueber', [now(), $heute])->first();
        $mn = QmScope::apply(Qm::t('qm_massnahmen as m')->leftJoin('objekte as o', 'o.obj_id', '=', 'm.obj_id'), $u, 'm.obj_id')
            ->selectRaw("SUM(m.status = 'offen') AS offen, SUM(m.status = 'offen' AND m.frist < ?) AS ueber,
                SUM(m.status = 'erledigt' AND m.wirksam_pruefung_am <= ?) AS pruefung", [$heute, $heute])->first();
        $vk = QmScope::apply(Qm::t('qm_wb_eintraege as w')->join('objekte as o', 'o.obj_id', '=', 'w.obj_id'), $u, 'w.obj_id')
            ->whereIn('w.art', ['vorkommnis', 'alarm'])->where('w.status', 'offen')->count();
        $daEntwurf = QmScope::apply(Qm::t('qm_dienstanweisungen as d')->join('objekte as o', 'o.obj_id', '=', 'd.obj_id'), $u, 'd.obj_id')
            ->where('d.status', 'entwurf')->count();

        $zuTun = [];
        $push = function (string $typ, string $icon, string $text, string $link, string $ton = '') use (&$zuTun) {
            $zuTun[] = compact('typ', 'icon', 'text', 'link', 'ton');
        };
        if ($vk) $push('wachbuch', 'notification_important', "$vk offene Vorkommnisse im Wachbuch", 'qm_wachbuch', 'warn');
        if ((int) $rek->best_ueber) $push('reklamation', 'mark_email_unread', $rek->best_ueber . ' Reklamationen ohne Eingangsbestätigung (Frist überschritten)', 'reklamationen', 'error');
        if ((int) $rek->ueber) $push('reklamation', 'alarm', $rek->ueber . ' Reklamationen über der Frist', 'reklamationen', 'error');
        if ((int) $mn->ueber) $push('massnahme', 'event_busy', $mn->ueber . ' Maßnahmen überfällig', 'massnahmen', 'error');
        if ((int) $mn->pruefung) $push('massnahme', 'fact_check', $mn->pruefung . ' Wirksamkeitsprüfungen fällig', 'massnahmen', 'warn');
        if ($daEntwurf && QmRechte::darf($u, 'da_freigeben')) $push('dienstanweisung', 'approval', "$daEntwurf Dienstanweisungen warten auf Freigabe", 'objekte', 'warn');

        return response()->json([
            'kpi' => [
                'reklamationen_offen' => (int) $rek->offen, 'reklamationen_ueber' => (int) $rek->ueber,
                'massnahmen_offen' => (int) $mn->offen, 'massnahmen_ueber' => (int) $mn->ueber,
                'vorkommnisse_offen' => $vk, 'da_entwurf' => $daEntwurf,
            ],
            'heute_zu_tun' => $zuTun,
        ]);
    }
}
