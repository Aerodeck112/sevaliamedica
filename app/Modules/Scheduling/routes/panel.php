<?php

use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\CalendarController;
use App\Modules\Scheduling\Http\Controllers\RequestController;
use App\Modules\Scheduling\Http\Controllers\ScheduleController;
use App\Modules\Scheduling\Http\Controllers\SeriesController;
use App\Modules\Scheduling\Http\Controllers\ServiceRecordController;
use App\Modules\Scheduling\Http\Controllers\SettingsController;
use App\Modules\Scheduling\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:scheduling.view')->group(function () {
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('programari/{appointment}', [AppointmentController::class, 'show'])->whereNumber('appointment')->name('appointments.show');
    Route::post('programari/{appointment}/status', [AppointmentController::class, 'status'])->whereNumber('appointment')->name('appointments.status');
    Route::get('serii', [SeriesController::class, 'index'])->name('series.index');
    Route::get('serii/{series}', [SeriesController::class, 'show'])->whereNumber('series')->name('series.show');
});

Route::middleware('permission:scheduling.manage')->group(function () {
    Route::get('programari/noua', [AppointmentController::class, 'create'])->name('appointments.create');
    Route::post('programari', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::get('programari/sugestii', [AppointmentController::class, 'suggestions'])->middleware('throttle:120,1')->name('appointments.suggestions');
    Route::post('programari/{appointment}/muta', [AppointmentController::class, 'move'])->whereNumber('appointment')->name('appointments.move');
    Route::post('programari/{appointment}/link-pacient', [AppointmentController::class, 'issueLink'])->whereNumber('appointment')->name('appointments.link');
    Route::post('programari/{appointment}/pacient', [AppointmentController::class, 'attachPatient'])->whereNumber('appointment')->name('appointments.attach-patient');

    Route::get('serii/noua', [SeriesController::class, 'create'])->name('series.create');
    Route::post('serii/plan', [SeriesController::class, 'plan'])->name('series.plan');
    Route::post('serii', [SeriesController::class, 'store'])->name('series.store');
    Route::post('serii/{series}/plan-reprogramare', [SeriesController::class, 'planReschedule'])->whereNumber('series')->name('series.plan-reschedule');
    Route::post('serii/{series}/reprogramare', [SeriesController::class, 'reschedule'])->whereNumber('series')->name('series.reschedule');
});

Route::middleware('permission:scheduling.requests')->prefix('solicitari')->name('requests.')->group(function () {
    Route::get('/', [RequestController::class, 'index'])->name('index');
    Route::get('{appointment}', [RequestController::class, 'show'])->whereNumber('appointment')->name('show');
    Route::post('{appointment}/pacient', [RequestController::class, 'attachPatient'])->whereNumber('appointment')->name('attach');
    Route::post('{appointment}/confirma', [RequestController::class, 'confirm'])->whereNumber('appointment')->name('confirm');
});

Route::middleware('permission:waitlist.manage')->prefix('lista-asteptare')->name('waitlist.')->group(function () {
    Route::get('/', [WaitlistController::class, 'index'])->name('index');
    Route::post('/', [WaitlistController::class, 'store'])->name('store');
    Route::post('{entry}/ofera', [WaitlistController::class, 'offer'])->whereNumber('entry')->name('offer');
    Route::post('{entry}/status', [WaitlistController::class, 'status'])->whereNumber('entry')->name('status');
});

Route::middleware('permission:schedules.manage')->prefix('program-lucru')->name('schedules.')->group(function () {
    Route::get('/', [ScheduleController::class, 'index'])->name('index');
    Route::get('{staff}', [ScheduleController::class, 'show'])->whereNumber('staff')->name('show');
    Route::post('{staff}/sabloane', [ScheduleController::class, 'storeTemplate'])->whereNumber('staff')->name('templates.store');
    Route::delete('sabloane/{template}', [ScheduleController::class, 'destroyTemplate'])->whereNumber('template')->name('templates.destroy');
    Route::post('exceptii', [ScheduleController::class, 'storeException'])->name('exceptions.store');
    Route::delete('exceptii/{exception}', [ScheduleController::class, 'destroyException'])->whereNumber('exception')->name('exceptions.destroy');
});

Route::middleware('permission:services.record')->group(function () {
    Route::post('programari/{appointment}/servicii', [ServiceRecordController::class, 'storeFromAppointment'])->whereNumber('appointment')->name('service-records.from-appointment');
    Route::post('pacienti/{patient}/servicii', [ServiceRecordController::class, 'storeManual'])->whereNumber('patient')->name('service-records.manual');
});
Route::post('servicii-efectuate/{record}/anuleaza', [ServiceRecordController::class, 'cancel'])->whereNumber('record')
    ->middleware('permission:services.correct')->name('service-records.cancel');

Route::middleware('permission:scheduling.settings')->group(function () {
    Route::get('reguli-programari', [SettingsController::class, 'edit'])->name('scheduling-settings.edit');
    Route::put('reguli-programari', [SettingsController::class, 'update'])->name('scheduling-settings.update');
});
