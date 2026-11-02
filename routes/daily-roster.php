<?php

declare(strict_types=1);

use App\Http\Controllers\DailyRoster\DailyRosterController;
use App\Http\Controllers\HomeDutyController;
use App\Http\Middleware\CanonicalHostRedirect;
use App\Http\Middleware\PreventPreviousUrlStorage;
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

Route::get('/daily-roster/changes', [DailyRosterController::class, 'changes'])
    ->middleware(['auth:web', 'throttle:60,1'])
    ->name('daily-roster.changes');

Route::get('/daily-roster/members/{employeeId}', [DailyRosterController::class, 'member'])
    ->where('employeeId', '[^/]{1,32}')
    ->middleware(['auth:web', 'throttle:daily-roster-member'])
    ->name('daily-roster.member');

Route::get('/daily-roster/pdf', [DailyRosterController::class, 'pdf'])
    ->middleware(['auth:web', 'throttle:daily-roster-pdf'])
    ->name('daily-roster.pdf');

/*
| The Home greeting block (shift on duty and the 300) for a page left open. The same read model as the page,
| personal and uncached, in this file because it shows what the roster says. DailyRosterAccess decides what
| it returns; a member it does not admit gets the greeting and the date only.
*/
Route::get('/home/duty', [HomeDutyController::class, 'show'])
    ->middleware(['auth:web', PreventPreviousUrlStorage::class, 'throttle:30,1,home-duty'])
    ->name('home.duty');
