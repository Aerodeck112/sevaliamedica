<?php

use App\Modules\Identity\Http\Controllers\AuditController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('deconectare', [AuthController::class, 'logout'])->name('logout');

Route::get('mfa/configurare', [MfaController::class, 'setup'])->name('mfa.setup');
Route::post('mfa/configurare', [MfaController::class, 'confirm'])->middleware('throttle:10,1')->name('mfa.confirm');
Route::post('mfa/coduri-recuperare', [MfaController::class, 'regenerateCodes'])->middleware('reauth')->name('mfa.codes');

Route::get('reconfirmare', [AuthController::class, 'showReauth'])->name('reauth');
Route::post('reconfirmare', [AuthController::class, 'reauth'])->middleware('throttle:10,1')->name('reauth.confirm');

Route::get('profil', [ProfileController::class, 'show'])->name('profile');
Route::put('profil/parola', [ProfileController::class, 'updatePassword'])->name('profile.password');
Route::delete('profil/sesiuni/{session}', [ProfileController::class, 'revokeSession'])->name('profile.sessions.revoke');
Route::delete('profil/sesiuni', [ProfileController::class, 'revokeOtherSessions'])->name('profile.sessions.revoke-others');

Route::middleware('permission:users.manage')->group(function () {
    Route::get('utilizatori', [UserController::class, 'index'])->name('users.index');
    Route::middleware('reauth')->group(function () {
        Route::get('utilizatori/nou', [UserController::class, 'create'])->name('users.create');
        Route::post('utilizatori', [UserController::class, 'store'])->name('users.store');
        Route::get('utilizatori/{user}', [UserController::class, 'edit'])->name('users.edit');
        Route::put('utilizatori/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('utilizatori/{user}/reset-mfa', [UserController::class, 'resetMfa'])->name('users.reset-mfa');
        Route::post('utilizatori/{user}/inchide-sesiuni', [UserController::class, 'revokeSessions'])->name('users.revoke-sessions');
        Route::post('utilizatori/{user}/resetare-parola', [UserController::class, 'sendReset'])->name('users.send-reset');
    });
});

Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
