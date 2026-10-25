<?php

use App\Modules\Backup\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:backups.manage')->prefix('backupuri')->name('backups.')->group(function () {
    Route::get('/', [BackupController::class, 'index'])->name('index');
    Route::post('/', [BackupController::class, 'store'])->middleware('reauth')->name('store');
    Route::get('{uuid}', [BackupController::class, 'show'])->whereUuid('uuid')->name('show');
    Route::post('{uuid}/continua', [BackupController::class, 'advance'])->whereUuid('uuid')->name('advance');
    Route::post('{uuid}/verifica', [BackupController::class, 'verify'])->whereUuid('uuid')->name('verify');
    Route::get('{uuid}/descarca', [BackupController::class, 'download'])->whereUuid('uuid')->middleware('reauth')->name('download');
});
