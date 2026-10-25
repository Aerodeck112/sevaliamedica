<?php

use App\Modules\Updates\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:updates.manage')->prefix('actualizari')->name('updates.')->group(function () {
    Route::get('/', [UpdateController::class, 'index'])->name('index');
    Route::get('mediu', [UpdateController::class, 'environment'])->name('environment');
    Route::post('/', [UpdateController::class, 'upload'])->middleware('reauth')->name('upload');
    Route::get('{uuid}', [UpdateController::class, 'show'])->whereUuid('uuid')->name('show');
    Route::post('{uuid}/porneste', [UpdateController::class, 'start'])->whereUuid('uuid')->middleware('reauth')->name('start');
    // Continuarea unei actualizări deja autorizate nu cere o nouă reconfirmare (pașii pot dura peste 10 minute).
    Route::post('{uuid}/continua', [UpdateController::class, 'advance'])->whereUuid('uuid')->name('advance');
    Route::post('{uuid}/anuleaza', [UpdateController::class, 'cancel'])->whereUuid('uuid')->name('cancel');
    Route::post('{uuid}/recuperare', [UpdateController::class, 'recover'])->whereUuid('uuid')->middleware('reauth')->name('recover');
    Route::post('{uuid}/restaurare-continua', [UpdateController::class, 'continueRestore'])->whereUuid('uuid')->name('restore.continue');
});
