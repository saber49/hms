<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Public PDF share view (no auth). Token in URL is the secret.
Route::get('/share/{slug}/{token}', [\App\Http\Controllers\Api\PdfController::class, 'publicShareView'])
    ->where('token', '[a-f0-9]{64}')
    ->name('pdf.public-share');


