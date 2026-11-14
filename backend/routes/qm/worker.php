<?php

/*
|--------------------------------------------------------------------------
| Qualitätsmanagement — worker app (required INSIDE the worker.auth group)
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\Qm\WorkerApp\WaDienstanweisungController;
use App\Http\Controllers\Api\Qm\WorkerApp\WaWachbuchController;
use Illuminate\Support\Facades\Route;

// Old installed apps (pre-QM Wachbuch modal) — see WaLegacyController.
Route::get('/wachbuch', [\App\Http\Controllers\Api\Qm\WorkerApp\WaLegacyController::class, 'show']);
Route::post('/wachbuch', [\App\Http\Controllers\Api\Qm\WorkerApp\WaLegacyController::class, 'store'])->middleware('throttle:qm-worker');

Route::prefix('qm')->group(function () {
    Route::get('/live/config', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'config']);
    Route::post('/live/auth', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'auth'])->middleware('throttle:qm-worker');
    Route::get('/wachbuch', [WaWachbuchController::class, 'show']);
    Route::post('/wachbuch/eintraege', [WaWachbuchController::class, 'store'])->middleware('throttle:qm-worker');
    Route::post('/wachbuch/eintraege/{id}/fotos', [WaWachbuchController::class, 'foto'])->whereNumber('id')->middleware('throttle:qm-worker');
    Route::get('/wachbuch/fotos/{id}', [WaWachbuchController::class, 'fotoAnzeigen'])->whereNumber('id');
    Route::post('/wachbuch/hinweise/{id}/umgesetzt', [WaWachbuchController::class, 'umgesetzt'])->whereNumber('id');

    Route::get('/dienstanweisungen', [WaDienstanweisungController::class, 'index']);
    Route::get('/dienstanweisungen/{id}/pdf', [WaDienstanweisungController::class, 'pdf'])->whereNumber('id');
    Route::get('/dienstanweisungen/{id}/link', [WaDienstanweisungController::class, 'link'])->whereNumber('id');
    Route::post('/dienstanweisungen/{id}/bestaetigen', [WaDienstanweisungController::class, 'bestaetigen'])->whereNumber('id');

    Route::get('/pin', [WaDienstanweisungController::class, 'pinStatus']);
    Route::post('/pin', [WaDienstanweisungController::class, 'pinAendern'])->middleware('throttle:qm-pin');
});
