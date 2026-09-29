<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
Route::get('/', [AuthController::class, 'home'])->name('home');
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'showLogin'])->name('login');Route::post('/login',[AuthController::class,'login'])->name('login.submit');Route::get('/register',[AuthController::class,'showRegister'])->name('register');Route::post('/register',[AuthController::class,'register'])->name('register.submit');});
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth','company'])->group(function(){
 Route::get('/chat',[ChatController::class,'index'])->name('chat');
 Route::post('/presence/heartbeat',[ChatController::class,'heartbeat'])->name('presence.heartbeat');
 Route::get('/company',[AdminController::class,'dashboard'])->middleware('role:company_super_admin,pharmacy_manager')->name('company.dashboard');
 Route::get('/profile',[ProfileController::class,'show'])->name('profile.show');
 Route::patch('/profile',[ProfileController::class,'update'])->name('profile.update');
 Route::patch('/profile/password',[ProfileController::class,'updatePassword'])->name('profile.password.update');
 Route::get('/notifications',[NotificationController::class,'index'])->name('notifications.index');
 Route::patch('/notifications/{notification}/read',[NotificationController::class,'read'])->name('notifications.read');
 Route::post('/notifications/read-all',[NotificationController::class,'readAll'])->name('notifications.read-all');
 Route::delete('/notifications',[NotificationController::class,'clear'])->name('notifications.clear');
 Route::post('/push/subscribe',[NotificationController::class,'subscribe'])->name('push.subscribe');
 Route::post('/push/unsubscribe',[NotificationController::class,'unsubscribe'])->name('push.unsubscribe'); Route::post('/conversations',[ChatController::class,'create'])->name('conversations.create'); Route::post('/conversations/{conversation}/messages',[ChatController::class,'send'])->name('messages.send'); Route::get('/conversations/{conversation}/messages/history',[ChatController::class,'olderMessages'])->name('messages.history'); Route::get('/conversations/{conversation}/messages/latest',[ChatController::class,'latestMessages'])->name('messages.latest'); Route::patch('/messages/{message}',[ChatController::class,'edit'])->name('messages.edit'); Route::delete('/messages/{message}',[ChatController::class,'destroy'])->name('messages.destroy'); Route::post('/messages/{message}/forward',[ChatController::class,'forward'])->name('messages.forward'); Route::get('/attachments/{attachment}',[ChatController::class,'download'])->name('attachments.download'); Route::get('/attachments/{attachment}/preview',[ChatController::class,'preview'])->name('attachments.preview');
 Route::prefix('admin')->middleware('role:company_super_admin,pharmacy_manager')->group(function(){Route::get('/',[AdminController::class,'dashboard'])->name('admin.dashboard');Route::get('/employees',[AdminController::class,'employees'])->name('admin.employees');Route::post('/employees',[AdminController::class,'storeEmployee'])->name('admin.employees.store');Route::patch('/employees/{user}',[AdminController::class,'updateEmployee'])->name('admin.employees.update');Route::delete('/employees/{user}',[AdminController::class,'deleteEmployee'])->name('admin.employees.delete');Route::get('/audit-logs',[AdminController::class,'audit'])->name('admin.audit');Route::get('/privacy',[AdminController::class,'privacy'])->name('admin.privacy');});
});
