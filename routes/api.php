<?php

use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\ChatController;
use App\Http\Middleware\RequireJsonResponse;
use Illuminate\Support\Facades\Route;

// Same-origin browser API: sessions, MFA and CSRF remain enforced.
Route::middleware([RequireJsonResponse::class, 'web', 'auth', 'auth.session', 'security', 'throttle:120,1'])->group(function () {
    Route::get('/me', [WorkspaceController::class, 'legacyMe'])->name('api.me');
    Route::prefix('v1')->name('api.v1.')->group(function () {
        Route::get('/me', [WorkspaceController::class, 'me'])->name('me');
        Route::middleware('company')->group(function () {
            Route::get('/conversations', [WorkspaceController::class, 'conversations'])->name('conversations.index');
            Route::get('/conversations/{conversation}/messages', [ChatController::class, 'updates'])->name('messages.index');
            Route::get('/conversations/{conversation}/history', [ChatController::class, 'history'])->name('messages.history');
            Route::post('/conversations/{conversation}/read', [ChatController::class, 'read'])->name('conversations.read');
            Route::post('/conversations/{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('messages.store');
            Route::get('/presence', [ChatController::class, 'presence'])->name('presence.index');
        });
    });
});
