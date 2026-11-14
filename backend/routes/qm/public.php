<?php

/*
|--------------------------------------------------------------------------
| QM — public signed links (required at top level of routes/api.php)
|--------------------------------------------------------------------------
| Dienstanweisung PDF for the native viewer / browser tab: 10-minute
| temporary signed URL issued by an authenticated worker/terminal request.
*/

use App\Http\Controllers\Api\Qm\WorkerApp\WaDienstanweisungController;
use Illuminate\Support\Facades\Route;

Route::get('/qm-da/{t}/{da}/{dn}', [WaDienstanweisungController::class, 'signiert'])
    ->whereNumber('t')->whereNumber('da')->where('dn', '[A-Za-z0-9_-]{1,10}')
    ->middleware(['signed', 'throttle:60,1'])->name('qm.da.pdf');
