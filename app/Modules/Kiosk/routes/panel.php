<?php

use App\Modules\Kiosk\Http\Controllers\DeviceController;
use App\Modules\Kiosk\Http\Controllers\ReviewController;
use App\Modules\Kiosk\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('tablete')->name('kiosk.')->group(function () {
    Route::middleware('permission:kiosk.devices')->group(function () {
        Route::get('/', [DeviceController::class, 'index'])->name('devices');
        Route::post('/', [DeviceController::class, 'store'])->name('devices.store');
        Route::post('{device}/asociere', [DeviceController::class, 'pairing'])->name('devices.pairing');
        Route::post('{device}/deconectare', [DeviceController::class, 'revoke'])->name('devices.revoke');
        Route::put('setari', [DeviceController::class, 'settings'])->name('settings');
    });
    Route::post('pacienti/{patient}/consimtamant', [SessionController::class, 'storeConsent'])->middleware('permission:kiosk.consent.send')->name('sessions.consent');
    Route::post('servicii/{record}/recenzie', [SessionController::class, 'storeReview'])->middleware('permission:kiosk.review.send')->name('sessions.review');
    Route::post('cereri/{session}/anuleaza', [SessionController::class, 'cancel'])->middleware('permission:kiosk.consent.send')->name('sessions.cancel');
});

Route::get('recenzii', [ReviewController::class, 'index'])->middleware('permission:reviews.view')->name('kiosk.reviews');
Route::post('recenzii/{review}/publicare', [ReviewController::class, 'publish'])->middleware('permission:reviews.moderate')->name('kiosk.reviews.publish');
