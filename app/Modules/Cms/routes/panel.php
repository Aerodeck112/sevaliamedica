<?php

use App\Modules\Cms\Http\Controllers\MediaController;
use App\Modules\Cms\Http\Controllers\MenuController;
use App\Modules\Cms\Http\Controllers\PageController;
use App\Modules\Cms\Http\Controllers\RedirectController;
use App\Modules\Cms\Http\Controllers\SettingsController;
use App\Modules\Cms\Http\Controllers\SubmissionController;
use App\Modules\Cms\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::prefix('site')->name('cms.')->group(function () {
    Route::middleware('permission:cms.edit')->group(function () {
        Route::get('pagini', [PageController::class, 'index'])->name('pages.index');
        Route::get('articole', [PageController::class, 'articles'])->name('articles.index');
        Route::get('pagini/noua', [PageController::class, 'create'])->name('pages.create');
        Route::post('pagini', [PageController::class, 'store'])->name('pages.store');
        Route::get('pagini/{page}', [PageController::class, 'edit'])->name('pages.edit');
        Route::put('pagini/{page}', [PageController::class, 'update'])->name('pages.update');
        Route::get('pagini/{page}/previzualizare', [PageController::class, 'preview'])->name('pages.preview');
        Route::get('pagini/{page}/istoric', [PageController::class, 'versions'])->name('pages.versions');
        Route::post('pagini/{page}/editare-activa', [PageController::class, 'heartbeat'])->name('pages.heartbeat');
        Route::post('pagini/{page}/eliberare', [PageController::class, 'release'])->name('pages.release');
        Route::get('subsol', [PageController::class, 'footer'])->name('footer.edit');
    });
    Route::middleware('permission:cms.publish')->group(function () {
        Route::post('pagini/{page}/publicare', [PageController::class, 'publish'])->name('pages.publish');
        Route::post('pagini/{page}/retragere', [PageController::class, 'unpublish'])->name('pages.unpublish');
        Route::post('pagini/{page}/versiuni/{version}/restaurare', [PageController::class, 'restore'])->name('pages.restore');
    });

    Route::middleware('permission:cms.media')->group(function () {
        Route::get('media', [MediaController::class, 'index'])->name('media.index');
        Route::get('media/alege', [MediaController::class, 'picker'])->name('media.picker');
        Route::post('media', [MediaController::class, 'store'])->name('media.store');
        Route::put('media/{media}', [MediaController::class, 'update'])->name('media.update');
        Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
    });

    Route::middleware('permission:cms.menus')->group(function () {
        Route::get('meniuri', [MenuController::class, 'index'])->name('menus.index');
        Route::get('meniuri/nou', [MenuController::class, 'create'])->name('menus.create');
        Route::post('meniuri', [MenuController::class, 'store'])->name('menus.store');
        Route::get('meniuri/{item}', [MenuController::class, 'edit'])->name('menus.edit');
        Route::put('meniuri/{item}', [MenuController::class, 'update'])->name('menus.update');
        Route::post('meniuri/{item}/mutare', [MenuController::class, 'move'])->name('menus.move');
        Route::delete('meniuri/{item}', [MenuController::class, 'destroy'])->name('menus.destroy');
    });

    Route::middleware('permission:cms.redirects')->group(function () {
        Route::get('redirecturi', [RedirectController::class, 'index'])->name('redirects.index');
        Route::post('redirecturi', [RedirectController::class, 'store'])->name('redirects.store');
        Route::put('redirecturi/{redirect}', [RedirectController::class, 'update'])->name('redirects.update');
        Route::delete('redirecturi/{redirect}', [RedirectController::class, 'destroy'])->name('redirects.destroy');
    });

    Route::middleware('permission:cms.settings')->group(function () {
        Route::get('setari', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('setari', [SettingsController::class, 'update'])->name('settings.update');
    });

    Route::middleware('permission:cms.tracking')->group(function () {
        Route::get('etichete', [TrackingController::class, 'edit'])->name('tracking.edit');
        Route::put('etichete', [TrackingController::class, 'update'])->name('tracking.update');
    });

    Route::middleware('permission:cms.submissions')->group(function () {
        Route::get('mesaje', [SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('mesaje/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
        Route::put('mesaje/{submission}', [SubmissionController::class, 'update'])->name('submissions.update');
    });
});
