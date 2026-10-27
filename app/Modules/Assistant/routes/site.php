<?php

use App\Modules\Assistant\Http\Controllers\AskController;
use Illuminate\Support\Facades\Route;

// Întrebarea vine în corpul cererii (POST), nu în URL: poate conține informații pe care vizitatorul nu ar trebui
// să le lase în jurnalele serverului.
Route::post('asistent/intrebare', AskController::class)->middleware('throttle:30,1')->name('asistent.ask');
