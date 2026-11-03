<?php

declare(strict_types=1);

use App\Http\Controllers\UnitStatus\UnitStatusController;
use App\Http\Middleware\CanonicalHostRedirect;
use App\Http\Middleware\UnitStatus\SignedOutBackgroundRefresh;
use Illuminate\Support\Facades\Route;

/*
| Unit Status: PulsePoint call status, runs and the on-duty crew for the 16 apparatus. Read only.
| Authorization (Daily Roster access) and the UNIT_STATUS_ENABLED flag are checked by the controller.
*/
Route::get('/unit-status', [UnitStatusController::class, 'show'])
    ->middleware([CanonicalHostRedirect::class, 'auth:web'])
    ->name('unit-status.show');

Route::get('/unit-status/board', [UnitStatusController::class, 'board'])
    ->middleware([SignedOutBackgroundRefresh::class.':web', 'throttle:60,1'])
    ->name('unit-status.board');

Route::get('/unit-status/data', [UnitStatusController::class, 'data'])
    ->middleware([SignedOutBackgroundRefresh::class.':web', 'throttle:60,1'])
    ->name('unit-status.data');

Route::get('/unit-status/units/{code}/runs', [UnitStatusController::class, 'runs'])
    ->where('code', '[A-Za-z0-9]{1,8}')
    ->middleware([SignedOutBackgroundRefresh::class.':web', 'throttle:60,1'])
    ->name('unit-status.runs');
