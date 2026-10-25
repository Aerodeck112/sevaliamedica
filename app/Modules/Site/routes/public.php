<?php

use App\Modules\Site\Http\Controllers\FormController;
use App\Modules\Site\Http\Controllers\PageController;
use App\Modules\Site\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('robots.txt', [SeoController::class, 'robots'])->name('site.robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('site.sitemap');

// Linkurile spre rețelele sociale păstrează adresele Odoo (/website/social/<rețea>).
Route::get('website/social/{network}', [PageController::class, 'social'])->whereIn('network', ['facebook', 'youtube', 'instagram', 'tiktok', 'linkedin'])->name('site.social');

// Paginarea blogului, ca în Odoo: /blog/blog-sevalia-1/page/2
Route::get('blog/blog-sevalia-1/page/{number}', [PageController::class, 'blogPage'])->whereNumber('number')->name('site.blog.page');

Route::post('formular/{form}', [FormController::class, 'submit'])->whereIn('form', ['contact', 'emsella'])
    ->middleware('throttle:20,1')->name('site.form.submit');
