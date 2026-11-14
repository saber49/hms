<?php

/*
|--------------------------------------------------------------------------
| Objekt-Terminal (QM) — own auth (qm.terminal), required at top level of
| routes/api.php. Wachbuch + Dienstanweisung reuse the worker-app QM
| controllers; QmTerminalAuth pins them to the terminal's Objekt.
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\Qm\Terminal\TerminalController;
use App\Http\Controllers\Api\Qm\WorkerApp\WaDienstanweisungController;
use App\Http\Controllers\Api\Qm\WorkerApp\WaWachbuchController;
use Illuminate\Support\Facades\Route;

Route::post('/qm-terminal/login', [TerminalController::class, 'login'])->middleware('throttle:qm-terminal');

Route::prefix('qm-terminal')->middleware(['qm.terminal', 'tenant.boundary'])->group(function () {
    Route::post('/logout', [TerminalController::class, 'logout']);
    Route::get('/status', [TerminalController::class, 'status']);
    Route::post('/wachperson', [TerminalController::class, 'anmelden'])->middleware('throttle:qm-pin');
    Route::post('/wachperson/abmelden', [TerminalController::class, 'abmelden']);
    Route::get('/live/config', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'config']);
    Route::post('/live/auth', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'auth'])->middleware('throttle:qm-worker');
});

Route::prefix('qm-terminal')->middleware(['qm.terminal:wachperson', 'tenant.boundary'])->group(function () {
    Route::post('/check-in', [TerminalController::class, 'checkIn']);
    Route::post('/check-out', [TerminalController::class, 'checkOut']);
    Route::get('/wachbuch', [WaWachbuchController::class, 'show']);
    Route::post('/wachbuch/eintraege', [WaWachbuchController::class, 'store'])->middleware('throttle:qm-worker');
    Route::post('/wachbuch/eintraege/{id}/fotos', [WaWachbuchController::class, 'foto'])->whereNumber('id')->middleware('throttle:qm-worker');
    Route::get('/wachbuch/fotos/{id}', [WaWachbuchController::class, 'fotoAnzeigen'])->whereNumber('id');
    Route::post('/wachbuch/hinweise/{id}/umgesetzt', [WaWachbuchController::class, 'umgesetzt'])->whereNumber('id');
    Route::get('/dienstanweisungen', [WaDienstanweisungController::class, 'index']);
    Route::get('/dienstanweisungen/{id}/pdf', [WaDienstanweisungController::class, 'pdf'])->whereNumber('id');
    Route::get('/dienstanweisungen/{id}/link', [WaDienstanweisungController::class, 'link'])->whereNumber('id');
    Route::post('/dienstanweisungen/{id}/bestaetigen', [WaDienstanweisungController::class, 'bestaetigen'])->whereNumber('id');
});
