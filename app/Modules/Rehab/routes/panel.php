<?php

use App\Modules\Rehab\Http\Controllers\PackageController;
use App\Modules\Rehab\Http\Controllers\PlanController;
use App\Modules\Rehab\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Pe lângă permisiuni, serviciile verifică relația de îngrijire pentru orice acțiune cu conținut clinic.
Route::prefix('recuperare')->name('rehab.')->group(function () {
    Route::get('planuri', [PlanController::class, 'index'])->middleware('permission:rehab.view')->name('plans.index');
    Route::get('planuri/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('plans.show');

    Route::middleware('permission:rehab.plan')->group(function () {
        Route::get('pacienti/{patient}/plan-nou', [PlanController::class, 'create'])->whereNumber('patient')->name('plans.create');
        Route::post('pacienti/{patient}/planuri', [PlanController::class, 'store'])->whereNumber('patient')->name('plans.store');
        Route::get('planuri/{plan}/editare', [PlanController::class, 'edit'])->whereNumber('plan')->name('plans.edit');
        Route::put('planuri/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
        Route::post('planuri/{plan}/proceduri', [PlanController::class, 'addItem'])->whereNumber('plan')->name('plans.items.store');
        Route::delete('proceduri/{item}', [PlanController::class, 'removeItem'])->whereNumber('item')->name('plans.items.destroy');
    });

    Route::middleware('permission:rehab.approve')->group(function () {
        Route::post('planuri/{plan}/aproba', [PlanController::class, 'approve'])->whereNumber('plan')->name('plans.approve');
        Route::post('planuri/{plan}/suspenda', [PlanController::class, 'suspend'])->whereNumber('plan')->name('plans.suspend');
        Route::post('planuri/{plan}/reia', [PlanController::class, 'resume'])->whereNumber('plan')->name('plans.resume');
        Route::post('planuri/{plan}/incheie', [PlanController::class, 'close'])->whereNumber('plan')->name('plans.close');
        Route::post('proceduri/{item}/sedinte', [PlanController::class, 'extendItem'])->whereNumber('item')->name('plans.items.extend');
    });

    Route::middleware('permission:rehab.schedule')->group(function () {
        Route::get('proceduri/{item}/programare', [PlanController::class, 'scheduleForm'])->whereNumber('item')->name('plans.items.schedule');
        Route::post('proceduri/{item}/programare/verificare', [PlanController::class, 'schedulePreview'])->whereNumber('item')->name('plans.items.schedule-preview');
        Route::post('proceduri/{item}/programare', [PlanController::class, 'scheduleStore'])->whereNumber('item')->name('plans.items.schedule-store');
    });

    Route::middleware('permission:rehab.sessions')->group(function () {
        Route::get('sedinte', [SessionController::class, 'index'])->name('sessions.index');
        Route::get('sedinte/{appointment}', [SessionController::class, 'create'])->whereNumber('appointment')->name('sessions.create');
        Route::post('sedinte/{appointment}', [SessionController::class, 'store'])->whereNumber('appointment')->name('sessions.store');
    });

    Route::middleware('permission:rehab.packages')->group(function () {
        Route::get('pachete', [PackageController::class, 'index'])->name('packages.index');
        Route::post('pachete', [PackageController::class, 'store'])->name('packages.store');
        Route::put('pachete/{definition}', [PackageController::class, 'update'])->whereNumber('definition')->name('packages.update');
    });

    Route::middleware('permission:rehab.packages.assign')->group(function () {
        Route::post('pacienti/{patient}/pachete', [PackageController::class, 'assign'])->whereNumber('patient')->name('packages.assign');
        Route::post('pachete-pacient/{package}/anuleaza', [PackageController::class, 'cancel'])->whereNumber('package')->name('packages.cancel');
    });
});
