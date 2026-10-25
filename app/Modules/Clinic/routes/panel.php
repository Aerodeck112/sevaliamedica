<?php

use App\Modules\Clinic\Http\Controllers\EquipmentController;
use App\Modules\Clinic\Http\Controllers\LocationController;
use App\Modules\Clinic\Http\Controllers\RoomController;
use App\Modules\Clinic\Http\Controllers\ServiceController;
use App\Modules\Clinic\Http\Controllers\SpecialtyController;
use App\Modules\Clinic\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:clinic.manage')->prefix('clinica')->name('clinic.')->group(function () {
    LocationController::routes('locatii', 'locations');
    SpecialtyController::routes('specialitati', 'specialties');
    StaffController::routes('personal', 'staff');
    RoomController::routes('sali', 'rooms');
    EquipmentController::routes('aparatura', 'equipment');
    ServiceController::routes('servicii', 'services');
    Route::post('servicii/{id}/resurse', [ServiceController::class, 'storeRequirement'])->name('services.requirements.store');
    Route::delete('servicii/{id}/resurse/{requirement}', [ServiceController::class, 'destroyRequirement'])->name('services.requirements.destroy');
});
