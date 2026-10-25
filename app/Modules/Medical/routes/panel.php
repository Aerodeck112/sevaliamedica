<?php

use App\Modules\Medical\Http\Controllers\AlertController;
use App\Modules\Medical\Http\Controllers\ConsentController;
use App\Modules\Medical\Http\Controllers\ConsentTemplateController;
use App\Modules\Medical\Http\Controllers\DocumentController;
use App\Modules\Medical\Http\Controllers\RecordController;
use App\Modules\Medical\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// Toate acțiunile verifică pe server, în plus, relația de îngrijire (MedicalAccess / CareAccess).
Route::prefix('medical')->name('medical.')->group(function () {
    Route::get('fise-de-finalizat', [RecordController::class, 'drafts'])->middleware('permission:medical.write')->name('drafts');

    Route::middleware('permission:medical.templates')->prefix('sabloane')->name('templates.')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('nou', [TemplateController::class, 'create'])->name('create');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::get('{template}', [TemplateController::class, 'edit'])->whereNumber('template')->name('edit');
        Route::put('{template}', [TemplateController::class, 'update'])->whereNumber('template')->name('update');
        Route::post('{template}/publica', [TemplateController::class, 'publish'])->whereNumber('template')->name('publish');
        Route::post('{template}/activ', [TemplateController::class, 'toggle'])->whereNumber('template')->name('toggle');
    });

    Route::middleware('permission:medical.consent_templates')->prefix('modele-consimtamant')->name('consent-templates.')->group(function () {
        Route::get('/', [ConsentTemplateController::class, 'index'])->name('index');
        Route::post('/', [ConsentTemplateController::class, 'store'])->name('store');
        Route::get('{template}', [ConsentTemplateController::class, 'edit'])->whereNumber('template')->name('edit');
        Route::post('{template}', [ConsentTemplateController::class, 'publish'])->whereNumber('template')->name('publish');
        Route::post('{template}/activ', [ConsentTemplateController::class, 'toggle'])->whereNumber('template')->name('toggle');
    });

    Route::middleware('permission:medical.write')->group(function () {
        Route::get('pacienti/{patient}/fise/noua', [RecordController::class, 'create'])->whereNumber('patient')->name('records.create');
        Route::post('pacienti/{patient}/fise', [RecordController::class, 'store'])->whereNumber('patient')->name('records.store');
        Route::get('fise/{record}/editare', [RecordController::class, 'edit'])->whereNumber('record')->name('records.edit');
        Route::put('fise/{record}', [RecordController::class, 'update'])->whereNumber('record')->name('records.update');
        Route::post('fise/{record}/abandon', [RecordController::class, 'abandon'])->whereNumber('record')->name('records.abandon');
        Route::post('pacienti/{patient}/documente', [DocumentController::class, 'store'])->whereNumber('patient')->middleware('throttle:30,1')->name('documents.store');
        Route::post('pacienti/{patient}/avertizari', [AlertController::class, 'store'])->whereNumber('patient')->name('alerts.store');
        Route::post('avertizari/{alert}/incheie', [AlertController::class, 'end'])->whereNumber('alert')->name('alerts.end');
    });

    // Finalizarea cere reconfirmarea identității (parolă + cod MFA) în ultimele minute.
    Route::post('fise/{record}/finalizare', [RecordController::class, 'finalize'])->whereNumber('record')
        ->middleware(['permission:medical.finalize', 'reauth'])->name('records.finalize');

    Route::middleware('permission:medical.amend')->group(function () {
        Route::get('fise/{record}/corectura', [RecordController::class, 'amendForm'])->whereNumber('record')->name('records.amend-form');
        Route::post('fise/{record}/corectura', [RecordController::class, 'amend'])->whereNumber('record')->name('records.amend');
    });

    Route::middleware('permission:medical.view')->group(function () {
        Route::get('fise/{record}', [RecordController::class, 'show'])->whereNumber('record')->name('records.show');
        Route::get('documente/{document}', [DocumentController::class, 'show'])->whereNumber('document')->middleware('throttle:120,1')->name('documents.show');
        Route::get('documente/{document}/descarca', [DocumentController::class, 'download'])->whereNumber('document')->middleware('throttle:60,1')->name('documents.download');
        Route::get('consimtaminte/{consent}', [ConsentController::class, 'show'])->whereNumber('consent')->name('consents.show');
    });

    Route::post('documente/{document}/retrage', [DocumentController::class, 'retract'])->whereNumber('document')
        ->middleware('permission:medical.documents.retract')->name('documents.retract');

    Route::middleware('permission:medical.consents')->group(function () {
        Route::post('pacienti/{patient}/consimtaminte', [ConsentController::class, 'store'])->whereNumber('patient')->name('consents.store');
        Route::post('consimtaminte/{consent}/retrage', [ConsentController::class, 'withdraw'])->whereNumber('consent')->name('consents.withdraw');
    });
});
