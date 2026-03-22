<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ===== Scheduled Tasks =====

// Daily AI priority analysis at 8 AM ET
Schedule::command('projects:analyze-priorities')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('ADMIN_EMAIL', 'admin@mbfd.org'))
    ->appendOutputTo(storage_path('logs/scheduled-tasks.log'));

// Check for overdue projects twice daily (8 AM and 5 PM)
Schedule::command('projects:check-overdue')
    ->twiceDailyAt(8, 17)
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer();

// Weekly summary every Monday at 9 AM
Schedule::command('projects:weekly-summary')
    ->weeklyOn(1, '09:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer();

// Milestone reminders daily at 7 AM
Schedule::command('projects:milestone-reminders')
    ->dailyAt('07:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer();

// Clean up old notification tracking records (older than 90 days)
Schedule::command('model:prune', [
    '--model' => [\App\Models\NotificationTracking::class],
])->daily();
