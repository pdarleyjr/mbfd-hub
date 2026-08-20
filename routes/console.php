<?php

use App\Services\VideoConferencing\ConferenceLineupNotifier;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

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

Schedule::command('personnel-equipment:notify-expirations')
    ->dailyAt('06:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('video-conferencing:expire-lineup', function (
    ConferenceSessionService $sessions,
    ConferenceLineupReadinessService $readiness,
    ConferenceLineupNotifier $notifier,
): int {
    $session = $sessions->activeLineup();
    if ($session === null || $session->started_at === null) {
        return Command::SUCCESS;
    }
    $maximumMinutes = max(5, min(30, (int) config('video-conferencing.lineup_max_minutes', 15)));
    if ($session->started_at->addMinutes($maximumMinutes)->isFuture()) {
        return Command::SUCCESS;
    }

    $sessions->end($session);
    $readiness->clear();
    $notifier->notify('expired');
    $this->info('Expired Morning Lineup '.$session->id.'.');

    return Command::SUCCESS;
})->purpose('End a Morning Lineup that exceeded its configured maximum duration');

Schedule::command('video-conferencing:expire-lineup')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
