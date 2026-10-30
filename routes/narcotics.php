<?php

declare(strict_types=1);

use App\Http\Controllers\Narcotics\CheckImageController;
use App\Http\Controllers\Narcotics\MemberCheckController;
use App\Http\Controllers\Narcotics\StaffCheckController;
use App\Http\Middleware\EnsureEmployeeAuthenticated;
use App\Http\Middleware\Narcotics\EnsureNarcoticsReviewer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Narcotics check JSON API (docs/narcotics-check.md)
|--------------------------------------------------------------------------
| Required from routes/web.php, so the web middleware group (session, CSRF) applies. The /api/ prefix is
| deliberate: the Daily Checkout service worker never stores /api/ responses, so a photo is never cached
| on a shared station device. Every response is {ok, data, meta} / {ok:false, error} and is never
| cached; the photo route answers 404 to anyone who may not see the check.
*/

Route::prefix('api/narcotics-checks')
    ->middleware(['auth:web'])
    ->name('narcotics.api.')
    ->group(function (): void {
        // Employee Portal: a signed-in member, their own checks only.
        Route::middleware([EnsureEmployeeAuthenticated::class, 'throttle:60,1,narcotics-member'])->group(function (): void {
            Route::get('/form', [MemberCheckController::class, 'form'])->name('form');
            Route::get('/mine', [MemberCheckController::class, 'index'])->name('mine.index');
            Route::get('/mine/{uuid}', [MemberCheckController::class, 'show'])->whereUuid('uuid')->name('mine.show');
            Route::post('/', [MemberCheckController::class, 'store'])->middleware('throttle:12,1,narcotics-submit')->name('store');
        });

        // The member who sent it, or Captain 5: decided inside the controller (404 for anyone else).
        Route::get('/{uuid}/image/{variant}', [CheckImageController::class, 'show'])
            ->whereUuid('uuid')->where('variant', 'thumb|preview|full')
            ->middleware('throttle:240,1,narcotics-image')
            ->name('checks.image');

        // Captain 5: the answer to "do I get the Narcotics Checks tab?" is a plain false, not a 403.
        Route::get('/staff/meta', [StaffCheckController::class, 'meta'])->middleware('throttle:120,1,narcotics-staff-meta')->name('staff.meta');

        Route::prefix('staff')->middleware([EnsureNarcoticsReviewer::class, 'throttle:120,1,narcotics-staff-read'])->name('staff.')->group(function (): void {
            Route::get('/checks', [StaffCheckController::class, 'index'])->name('index');
            Route::get('/checks/{uuid}', [StaffCheckController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('/checks/{uuid}/approve', [StaffCheckController::class, 'approve'])->middleware('throttle:60,1,narcotics-staff-write')->whereUuid('uuid')->name('approve');
            Route::post('/checks/{uuid}/reject', [StaffCheckController::class, 'reject'])->middleware('throttle:60,1,narcotics-staff-write')->whereUuid('uuid')->name('reject');
        });
    });
