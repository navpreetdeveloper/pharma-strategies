<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Services\Accounts\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/company/switch', function (Request $request) {
    $data = $request->validate(['company_id' => ['required', 'integer']]);
    abort_if($request->user()->isPlatformSuperAdmin(), 403, 'Platform Super Admins select workspaces from the Companies directory.');
    $company = $request->user()->companies()
        ->where('companies.id', $data['company_id'])
        ->where('companies.status', 'active')
        ->wherePivot('status', 'active')
        ->firstOrFail();
    $request->session()->put('company_id', $company->id);

    return redirect()->route($request->user()->landingRoute())->with('ok', 'Company switched.');
})->middleware(['auth', 'auth.session', 'security'])->name('company.switch');
Route::middleware(['auth', 'auth.session', 'security', 'company'])->group(function () {
    Route::get('/workspace/privacy', [PrivacyController::class, 'show'])->name('workspace.privacy');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');
    Route::post('/conversations/{conversation}/read', [ChatController::class, 'read'])->name('conversations.read');
    Route::get('/conversations/{conversation}/updates', [ChatController::class, 'updates'])->name('conversations.updates');
    Route::post('/presence/heartbeat', [ChatController::class, 'heartbeat'])->name('presence.heartbeat');
    Route::get('/presence', [ChatController::class, 'presence'])->name('presence.index');
    Route::get('/company', [AdminController::class, 'dashboard'])->middleware('role:company_super_admin,pharmacy_manager')->name('company.dashboard');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('/notifications', [NotificationController::class, 'clear'])->name('notifications.clear');
    Route::post('/push/subscribe', [NotificationController::class, 'subscribe'])->middleware('throttle:30,1')->name('push.subscribe');
    Route::post('/push/unsubscribe', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/conversations', [ChatController::class, 'create'])->middleware('throttle:30,1')->name('conversations.create');
    Route::post('/conversations/{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('messages.send');
    Route::patch('/messages/{message}', [ChatController::class, 'edit'])->name('messages.edit');
    Route::delete('/messages/{message}', [ChatController::class, 'destroy'])->name('messages.destroy');
    Route::post('/messages/{message}/forward', [ChatController::class, 'forward'])->middleware('throttle:30,1')->name('messages.forward');
    Route::get('/attachments/{attachment}', [ChatController::class, 'download'])->name('attachments.download');
    Route::get('/attachments/{attachment}/preview', [ChatController::class, 'preview'])->name('attachments.preview');
    Route::prefix('admin')->middleware('role:company_super_admin,pharmacy_manager')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/employees', [AdminController::class, 'employees'])->name('admin.employees');
        Route::post('/employees', [AdminController::class, 'storeEmployee'])->middleware('throttle:30,1')->name('admin.employees.store');
        Route::patch('/employees/{user}', [AdminController::class, 'updateEmployee'])->name('admin.employees.update');
        Route::delete('/employees/{user}', [AdminController::class, 'deleteEmployee'])->name('admin.employees.delete');
        Route::get('/audit-logs', [AdminController::class, 'audit'])->name('admin.audit');
        Route::get('/privacy', [AdminController::class, 'privacy'])->name('admin.privacy');
    });
});

Route::get('/workspace/access', fn (Request $request) => response()->json(['ok' => true] + SessionContext::make($request)))->middleware(['auth', 'auth.session', 'security', 'company'])->name('workspace.access');
Route::get('/messages/{message}', [ChatController::class, 'showMessage'])->middleware(['auth', 'auth.session', 'security', 'company'])->name('messages.show');
Route::get('/conversations/{conversation}/history', [ChatController::class, 'history'])->middleware(['auth', 'auth.session', 'security', 'company'])->name('messages.history');

Route::patch('/admin/privacy', [AdminController::class, 'updatePrivacy'])->middleware(['auth', 'auth.session', 'security', 'company', 'role:company_super_admin'])->name('admin.privacy.update');
