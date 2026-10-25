<?php

use App\Modules\Patients\Http\Controllers\BreakGlassController;
use App\Modules\Patients\Http\Controllers\CareRelationController;
use App\Modules\Patients\Http\Controllers\MergeController;
use App\Modules\Patients\Http\Controllers\PatientController;
use App\Modules\Patients\Http\Controllers\RepresentativeController;
use Illuminate\Support\Facades\Route;

Route::prefix('pacienti')->name('patients.')->group(function () {
    Route::middleware('permission:patients.view')->group(function () {
        Route::get('/', [PatientController::class, 'index'])->name('index');
        Route::get('cauta', [PatientController::class, 'search'])->middleware('throttle:120,1')->name('search');
        // Tabul se alege cu ?tab=<cheie> (vezi PatientTabRegistry).
        Route::get('{patient}', [PatientController::class, 'show'])->whereNumber('patient')->name('show');
    });

    Route::middleware('permission:patients.create')->group(function () {
        Route::get('nou', [PatientController::class, 'create'])->name('create');
        Route::post('/', [PatientController::class, 'store'])->name('store');
        Route::post('verifica-duplicate', [PatientController::class, 'checkDuplicates'])->name('duplicates');
    });

    Route::middleware('permission:patients.edit')->group(function () {
        Route::get('{patient}/editare', [PatientController::class, 'edit'])->whereNumber('patient')->name('edit');
        Route::put('{patient}', [PatientController::class, 'update'])->whereNumber('patient')->name('update');
        Route::post('{patient}/reprezentanti', [RepresentativeController::class, 'store'])->name('representatives.store');
        Route::delete('{patient}/reprezentanti/{representative}', [RepresentativeController::class, 'destroy'])->name('representatives.destroy');
    });

    Route::post('{patient}/cnp', [PatientController::class, 'revealCnp'])->middleware(['permission:patients.reveal_identifiers', 'throttle:30,1'])->name('reveal-cnp');

    Route::middleware('permission:patients.care_relations')->group(function () {
        Route::post('{patient}/ingrijire', [CareRelationController::class, 'store'])->name('care.store');
        Route::post('{patient}/ingrijire/{relation}/incheie', [CareRelationController::class, 'end'])->name('care.end');
    });

    Route::middleware('permission:patients.break_glass')->group(function () {
        Route::get('{patient}/acces-urgenta', [BreakGlassController::class, 'create'])->name('break-glass.create');
        Route::post('{patient}/acces-urgenta', [BreakGlassController::class, 'store'])->name('break-glass.store');
    });

    Route::middleware(['permission:patients.merge', 'reauth'])->group(function () {
        Route::get('{patient}/unire', [MergeController::class, 'create'])->name('merge.create');
        Route::post('{patient}/unire', [MergeController::class, 'store'])->name('merge.store');
    });
});
