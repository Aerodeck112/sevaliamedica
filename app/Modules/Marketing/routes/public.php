<?php

use App\Modules\Marketing\Http\Controllers\PublicFilesController;
use Illuminate\Support\Facades\Route;

Route::get('llms.txt', [PublicFilesController::class, 'llms'])->name('marketing.llms');
// Fișierul cheii IndexNow: /<32 de caractere hexazecimale>.txt
Route::get('{indexNowKey}.txt', [PublicFilesController::class, 'indexNowKey'])->where('indexNowKey', '[a-f0-9]{32}')->name('marketing.indexnow.key');
