<?php

use App\Modules\Scheduling\Http\Controllers\PatientLinkController;
use Illuminate\Support\Facades\Route;

// Linkuri pentru pacient: fără autentificare, protejate prin token, cu limitare de rată.
Route::prefix('p')->middleware('throttle:30,1')->name('patient-link.')->group(function () {
    Route::get('{token}', [PatientLinkController::class, 'show'])->where('token', '[A-Za-z0-9_-]{40,64}')->name('show');
    Route::post('{token}/anulare', [PatientLinkController::class, 'cancel'])->where('token', '[A-Za-z0-9_-]{40,64}')->name('cancel');
    Route::post('{token}/reprogramare', [PatientLinkController::class, 'reschedule'])->where('token', '[A-Za-z0-9_-]{40,64}')->name('reschedule');
});
