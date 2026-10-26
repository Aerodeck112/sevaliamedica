<?php

use App\Modules\Notifications\Http\Controllers\FeedbackController;
use Illuminate\Support\Facades\Route;

Route::get('feedback/{token}', [FeedbackController::class, 'show'])->middleware('throttle:30,1')->name('feedback.show');
Route::post('feedback/{token}', [FeedbackController::class, 'submit'])->middleware('throttle:10,1')->name('feedback.submit');
