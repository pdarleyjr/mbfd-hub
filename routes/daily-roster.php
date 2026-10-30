<?php

declare(strict_types=1);

use App\Http\Controllers\DailyRoster\DailyRosterController;
use App\Http\Middleware\CanonicalHostRedirect;
use Illuminate\Support\Facades\Route;

/*
| Daily Roster: members with a personnel profile and administrators.
| Authorization lives in App\Services\Security\DailyRosterAccess, checked by the controller.
| The version endpoint is in routes/api.php.
*/
Route::get('/daily-roster', [DailyRosterController::class, 'show'])
    ->middleware([CanonicalHostRedirect::class, 'auth:web'])
    ->name('daily-roster.show');

Route::get('/daily-roster/sections', [DailyRosterController::class, 'sections'])
    ->middleware(['auth:web', 'throttle:60,1'])
    ->name('daily-roster.sections');
