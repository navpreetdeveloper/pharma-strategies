<?php

use App\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');

Route::get('/', function () {
    return auth()->check() ? redirect()->route(auth()->user()->landingRoute()) : view('landing');
})->name('home');

require __DIR__.'/web/auth.php';
require __DIR__.'/web/platform.php';
require __DIR__.'/web/workspace.php';
