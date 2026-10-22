<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AuthController;
use App\Services\Accounts\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.submit');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/session/status', function (Request $request) {
    abort_unless($request->user()->status === 'active', 403);

    return response()->json(['authenticated' => true] + SessionContext::make($request))->header('Cache-Control', 'private, no-store');
})->middleware(['auth', 'auth.session'])->name('session.status');
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [AccountSecurityController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AccountSecurityController::class, 'sendReset'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [AccountSecurityController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AccountSecurityController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
    Route::get('/two-factor-challenge', [AccountSecurityController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/two-factor-challenge', [AccountSecurityController::class, 'confirmChallenge'])->middleware('throttle:6,1')->name('mfa.challenge.confirm');
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/verify-email', [AccountSecurityController::class, 'verifyNotice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AccountSecurityController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [AccountSecurityController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');
});
Route::middleware(['auth', 'auth.session', 'security'])->group(function () {
    Route::get('/account/authenticator', [AccountSecurityController::class, 'security'])->name('mfa.settings');
    Route::post('/account/authenticator', [AccountSecurityController::class, 'enable'])->middleware('throttle:6,1')->name('mfa.enable');
    Route::post('/account/authenticator/confirm', [AccountSecurityController::class, 'confirm'])->middleware('throttle:6,1')->name('mfa.confirm');
    Route::delete('/account/authenticator', [AccountSecurityController::class, 'disable'])->middleware('throttle:6,1')->name('mfa.disable');
});
