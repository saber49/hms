<?php

/*
|--------------------------------------------------------------------------
| Kundenportal (part of the QM module) — required INSIDE the kunden.auth group
|--------------------------------------------------------------------------
| The public login route lives in routes/api.php (no auth).
*/

use App\Http\Controllers\Api\Qm\KundenPortal\PortalController;
use App\Http\Controllers\Api\Qm\KundenPortal\PortalQmController;
use Illuminate\Support\Facades\Route;

Route::post('/logout', [PortalController::class, 'logout']);
Route::get('/me', [PortalController::class, 'me']);
Route::post('/passwort', [PortalController::class, 'passwort']);
Route::get('/einsaetze', [PortalController::class, 'einsaetze']);
Route::get('/fotos', [PortalController::class, 'fotos']);
Route::get('/fotos/{id}', [PortalController::class, 'foto'])->whereNumber('id');

Route::get('/wachbuch', [PortalQmController::class, 'wachbuch']);
Route::get('/wachbuch/pdf', [PortalQmController::class, 'wachbuchPdf']);
Route::get('/wachbuch/fotos/{id}', [PortalQmController::class, 'wachbuchFoto'])->whereNumber('id');
Route::post('/wachbuch/hinweis', [PortalQmController::class, 'hinweis']);
Route::get('/dienstanweisungen', [PortalQmController::class, 'dienstanweisungen']);
Route::get('/dienstanweisungen/{id}/pdf', [PortalQmController::class, 'dienstanweisungPdf'])->whereNumber('id');
Route::post('/dienstanweisungen/{id}', [PortalQmController::class, 'dienstanweisungAktion'])->whereNumber('id');
Route::get('/reklamationen', [PortalQmController::class, 'reklamationen']);
Route::post('/reklamationen', [PortalQmController::class, 'reklamationMelden']);
Route::get('/massnahmen', [PortalQmController::class, 'massnahmen']);
Route::get('/live/config', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'config']);
Route::post('/live/auth', [\App\Http\Controllers\Api\Qm\Live\LiveController::class, 'auth']);
