<?php

use App\Modules\Kiosk\Http\Controllers\TabletController;
use App\Modules\Kiosk\Http\Middleware\KioskDeviceAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('tableta')->name('kiosk.')->group(function () {
    Route::get('asociere', [TabletController::class, 'showPair'])->name('pair');
    Route::post('asociere', [TabletController::class, 'pair'])->middleware('throttle:10,1')->name('pair.store');

    Route::middleware(KioskDeviceAuth::class)->group(function () {
        Route::get('/', [TabletController::class, 'home'])->name('home');
        Route::post('cod', [TabletController::class, 'enterCode'])->middleware('throttle:20,1')->name('code');
        Route::get('asteptare', [TabletController::class, 'poll'])->middleware('throttle:60,1')->name('poll');
        Route::get('cerere', [TabletController::class, 'showRequest'])->name('request');
        Route::post('consimtamant', [TabletController::class, 'consent'])->middleware('throttle:10,1')->name('consent');
        Route::post('recenzie', [TabletController::class, 'review'])->middleware('throttle:10,1')->name('review');
        Route::get('multumim', [TabletController::class, 'thanks'])->name('thanks');
    });
});
