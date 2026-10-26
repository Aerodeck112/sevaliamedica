<?php

use App\Modules\Notifications\Http\Controllers\ChannelController;
use App\Modules\Notifications\Http\Controllers\DeliveryController;
use App\Modules\Notifications\Http\Controllers\FeedbackController;
use App\Modules\Notifications\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('notificari')->name('notifications.')->group(function () {
    Route::middleware('permission:notifications.view')->group(function () {
        Route::get('/', [DeliveryController::class, 'index'])->name('deliveries');
        Route::get('livrari/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery')->name('deliveries.show');
    });
    Route::post('livrari/{delivery}/reincearca', [DeliveryController::class, 'retry'])->whereNumber('delivery')
        ->middleware('permission:notifications.retry')->name('deliveries.retry');
    Route::middleware('permission:notifications.send')->group(function () {
        Route::get('mesaj-nou', [DeliveryController::class, 'create'])->name('deliveries.create');
        Route::post('mesaj-nou', [DeliveryController::class, 'store'])->name('deliveries.store');
    });

    Route::middleware('permission:notifications.templates')->group(function () {
        Route::get('sabloane', [TemplateController::class, 'index'])->name('templates');
        Route::get('sabloane/{type}/{channel}', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::put('sabloane/{type}/{channel}', [TemplateController::class, 'update'])->name('templates.update');
        Route::post('sabloane/versiune/{template}/restaureaza', [TemplateController::class, 'restore'])->name('templates.restore');
    });

    Route::middleware('permission:notifications.settings')->group(function () {
        Route::get('canale', [ChannelController::class, 'edit'])->name('channels');
        Route::put('canale/reguli', [ChannelController::class, 'updateSettings'])->name('channels.settings');
        // Credențialele furnizorilor se schimbă numai după reconfirmarea identității.
        Route::put('canale/{channel}', [ChannelController::class, 'updateChannel'])->middleware('reauth')->name('channels.update');
        Route::post('canale/{channel}/test', [ChannelController::class, 'test'])->middleware('throttle:10,1')->name('channels.test');
    });

    Route::get('feedback', [FeedbackController::class, 'index'])->middleware('permission:feedback.view')->name('feedback');
});
