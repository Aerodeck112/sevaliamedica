<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('panou')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('autentificare', [AuthController::class, 'showLogin'])->name('login');
        Route::post('autentificare', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.attempt');
        Route::get('parola-uitata', [PasswordResetController::class, 'showRequest'])->name('password.request');
        Route::post('parola-uitata', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
        Route::get('resetare-parola/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
        Route::post('resetare-parola', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
    });

    Route::middleware('auth')->group(function () {
        Route::get('autentificare/cod', [AuthController::class, 'showChallenge'])->name('mfa.challenge');
        Route::post('autentificare/cod', [AuthController::class, 'verifyChallenge'])->middleware('throttle:10,1')->name('mfa.verify');
    });
});
