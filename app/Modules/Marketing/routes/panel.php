<?php

use App\Modules\Marketing\Http\Controllers\IntegrationsController;
use App\Modules\Marketing\Http\Controllers\KeywordController;
use App\Modules\Marketing\Http\Controllers\SeoAuditController;
use App\Modules\Marketing\Http\Controllers\SeoFixController;
use Illuminate\Support\Facades\Route;

Route::prefix('marketing')->name('marketing.')->group(function () {
    Route::middleware('permission:marketing.seo')->group(function () {
        Route::get('audit-seo', [SeoAuditController::class, 'index'])->name('seo');
        Route::post('audit-seo', [SeoAuditController::class, 'start'])->name('seo.start');
        Route::post('audit-seo/{run}/continua', [SeoAuditController::class, 'continue'])->name('seo.continue');
        // Corectorul publică versiuni noi ale paginilor: cere și drepturile de SEO și publicare din CMS.
        Route::get('audit-seo/corector', [SeoFixController::class, 'index'])->middleware('permission:cms.seo')->name('seo.fix');
        Route::post('audit-seo/corector', [SeoFixController::class, 'apply'])->middleware(['permission:cms.seo', 'permission:cms.publish'])->name('seo.fix.apply');
        Route::get('cuvinte-cheie', [KeywordController::class, 'index'])->name('keywords');
        Route::post('cuvinte-cheie', [KeywordController::class, 'store'])->name('keywords.store');
        Route::put('cuvinte-cheie/{keyword}', [KeywordController::class, 'update'])->name('keywords.update');
        Route::delete('cuvinte-cheie/{keyword}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
    });
    Route::middleware('permission:marketing.integrations')->group(function () {
        Route::get('google-ai', [IntegrationsController::class, 'edit'])->name('integrations');
        Route::put('google-ai', [IntegrationsController::class, 'update'])->name('integrations.update');
        Route::post('google-ai/indexnow', [IntegrationsController::class, 'submitAll'])->name('integrations.indexnow');
    });
});
