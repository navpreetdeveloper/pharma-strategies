<?php

use App\Http\Controllers\PlatformCompanyController;
use App\Http\Controllers\PlatformUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform/companies')->name('platform.companies.')->middleware(['auth', 'auth.session', 'security', 'platform'])->group(function () {
    Route::get('/', [PlatformCompanyController::class, 'index'])->name('index');
    Route::post('/', [PlatformCompanyController::class, 'store'])->name('store');
    Route::patch('/{company}', [PlatformCompanyController::class, 'update'])->name('update');
    Route::patch('/{company}/status', [PlatformCompanyController::class, 'setStatus'])->name('status');
    Route::post('/{company}/workspace', [PlatformCompanyController::class, 'selectWorkspace'])->name('workspace');
});
Route::middleware(['auth', 'auth.session', 'security', 'platform'])->group(function () {
    Route::get('/platform/users', [PlatformUserController::class, 'index'])->name('platform.users.index');
    Route::post('/platform/users/{user}/password', [PlatformUserController::class, 'resetPassword'])->middleware('throttle:6,1')->name('platform.users.reset-password');
    Route::post('/platform/users/{user}/access', [PlatformUserController::class, 'restoreAccess'])->middleware('throttle:6,1')->name('platform.users.restore-access');
    Route::delete('/platform/users/{user}', [PlatformUserController::class, 'destroy'])->middleware('throttle:6,1')->name('platform.users.destroy');
    Route::get('/platform/activity', [PlatformUserController::class, 'audit'])->name('platform.audit');
    Route::get('/platform/employees', [PlatformCompanyController::class, 'employees'])->name('platform.employees.index');
    Route::post('/platform/employees/workspace', [PlatformCompanyController::class, 'selectEmployeeWorkspace'])->name('platform.employees.workspace');
});
