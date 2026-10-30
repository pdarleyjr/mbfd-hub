<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Notifications\CriticalAlertNotification;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells administrators when a roster needs a person: late, held or failed. Everything else stays on
 * the Roster Imports screen. One alert per kind per duty date, and a failed alert never stops the import.
 */
final class RosterAlerts
{
    private const MESSAGES = [
        'delayed' => 'Today\'s TeleStaff roster has not arrived. Members still see the last roster, marked as delayed.',
        'needs_review' => 'A TeleStaff roster was held for review. Members still see the last good roster.',
        'failed' => 'A TeleStaff roster email could not be processed. Members still see the last good roster.',
    ];

    public function __construct(private readonly NotificationRecipientEvaluator $recipients) {}

    public function send(string $kind, string $dutyDate): void
    {
        $message = self::MESSAGES[$kind] ?? null;
        $key = "daily_roster_alert:{$kind}:{$dutyDate}";
        if ($message === null || ! Cache::add($key, true, now()->addDays(2))) {
            return;
        }

        try {
            $recipients = $this->recipients->recipients('system_alerts');
            foreach ($recipients as $recipient) {
                FilamentNotification::make()->warning()->title('Daily Roster')->body($message)->sendToDatabase($recipient);
            }
            Notification::send($recipients, new CriticalAlertNotification('Daily Roster', $message, 'daily_roster', url: url('/admin/roster-imports')));
        } catch (Throwable $exception) {
            Cache::forget($key);
            report($exception);
        }
    }
}
