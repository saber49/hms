<?php

/*
|--------------------------------------------------------------------------
| Qualitätsmanagement & Wachbuch — office (Büro / QMB / Objektleiter)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the authenticated tenant group.
| `permission:qm.view` = any QM role; WHAT a role may do is checked in the
| controllers (QmRechte), WHICH objects in QmScope.
*/

use App\Http\Controllers\Api\Qm\Einstellungen\EinstellungenController;
use App\Http\Controllers\Api\Qm\Einstellungen\ZugaengeController;
use App\Http\Controllers\Api\Qm\KundenPortal\ZugangController;
use App\Http\Controllers\Api\Qm\Massnahme\MassnahmeController;
use App\Http\Controllers\Api\Qm\Objekte\DienstanweisungController;
use App\Http\Controllers\Api\Qm\Objekte\ObjektController;
use App\Http\Controllers\Api\Qm\QmController;
use App\Http\Controllers\Api\Qm\Reklamation\ReklamationController;
use App\Http\Controllers\Api\Qm\Wachbuch\LeitstandController;
use App\Http\Controllers\Api\Qm\Wachbuch\WachbuchblattController;
use Illuminate\Support\Facades\Route;

Route::prefix('qm')->middleware('permission:qm.view')->group(function () {
    Route::get('/optionen', [QmController::class, 'optionen']);
    Route::get('/uebersicht', [QmController::class, 'uebersicht']);

    // Wachbuch
    Route::get('/wachbuch/leitstand', [LeitstandController::class, 'index']);
    Route::get('/wachbuch/objekte/{objId}', [WachbuchblattController::class, 'objekt']);
    Route::get('/wachbuch/objekte/{objId}/tage', [WachbuchblattController::class, 'tage']);
    Route::get('/wachbuch/objekte/{objId}/tag/{datum}', [WachbuchblattController::class, 'tag']);
    Route::get('/wachbuch/fotos/{id}', [WachbuchblattController::class, 'foto'])->whereNumber('id');

    // Objekte & Dienstanweisungen
    Route::get('/objekte', [ObjektController::class, 'index']);
    Route::get('/objekte/{objId}', [ObjektController::class, 'show']);
    Route::get('/dienstanweisungen/{id}/pdf', [DienstanweisungController::class, 'pdf'])->whereNumber('id');

    // Reklamationen + Maßnahmen
    Route::get('/reklamationen', [ReklamationController::class, 'index']);
    Route::get('/reklamationen/{id}', [ReklamationController::class, 'show'])->whereNumber('id');
    Route::get('/massnahmen', [MassnahmeController::class, 'index']);
    Route::get('/massnahmen/{id}', [MassnahmeController::class, 'show'])->whereNumber('id');

    // Einstellungen (read: every QM role; writes are QMB-only in the controller)
    Route::get('/einstellungen', [EinstellungenController::class, 'show']);
    Route::get('/terminals', [ZugaengeController::class, 'terminals']);
    Route::get('/pins', [ZugaengeController::class, 'pins']);
    Route::get('/kundenzugaenge', [ZugangController::class, 'index']);
    Route::get('/kundenzugaenge/kunden', [ZugangController::class, 'kunden']);
});

// Writes — own throttle bucket per area is not needed: the global `api`
// limiter applies; never add a bare throttle:N here (shared bucket trap).
Route::prefix('qm')->middleware('permission:qm.view')->group(function () {
    Route::post('/wachbuch/eintraege', [WachbuchblattController::class, 'store']);
    Route::post('/wachbuch/eintraege/{id}/erledigt', [WachbuchblattController::class, 'erledigt'])->whereNumber('id');
    Route::post('/wachbuch/blaetter/{id}/gegenzeichnen', [WachbuchblattController::class, 'gegenzeichnen'])->whereNumber('id');

    Route::put('/objekte/{objId}/einstellung', [ObjektController::class, 'einstellung']);
    Route::post('/objekte/{objId}/dienstanweisungen', [DienstanweisungController::class, 'store']);
    Route::post('/dienstanweisungen/{id}/freigabe', [DienstanweisungController::class, 'freigabe'])->whereNumber('id');

    Route::post('/reklamationen', [ReklamationController::class, 'store']);
    Route::post('/reklamationen/{id}/{aktion}', [ReklamationController::class, 'aktion'])->whereNumber('id')
        ->whereIn('aktion', ['bestaetigen', 'sofortmassnahme', 'ursache', 'antworten', 'zustaendig', 'abschliessen', 'notiz']);

    Route::post('/massnahmen', [MassnahmeController::class, 'store']);
    Route::put('/massnahmen/{id}', [MassnahmeController::class, 'update'])->whereNumber('id');
    Route::post('/massnahmen/{id}/verlaengern', [MassnahmeController::class, 'verlaengern'])->whereNumber('id');
    Route::post('/massnahmen/{id}/erledigen', [MassnahmeController::class, 'erledigen'])->whereNumber('id');
    Route::post('/massnahmen/{id}/wirksamkeit', [MassnahmeController::class, 'wirksamkeit'])->whereNumber('id');

    Route::put('/einstellungen', [EinstellungenController::class, 'update']);
    Route::put('/einstellungen/rechte', [EinstellungenController::class, 'rechte']);
    Route::post('/terminals/{objId}/pin', [ZugaengeController::class, 'terminalPin']);
    Route::post('/terminals/{objId}/aktiv', [ZugaengeController::class, 'terminalAktiv']);
    Route::post('/pins/{dienstNr}/{aktion}', [ZugaengeController::class, 'pinAktion'])->whereIn('aktion', ['neu', 'sperren', 'entsperren']);
    Route::post('/kundenzugaenge', [ZugangController::class, 'store']);
    Route::put('/kundenzugaenge/{id}', [ZugangController::class, 'update'])->whereNumber('id');
    Route::post('/kundenzugaenge/{id}/{aktion}', [ZugangController::class, 'aktion'])->whereNumber('id')->whereIn('aktion', ['passwort', 'entsperren']);
});
