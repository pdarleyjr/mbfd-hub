<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Notifications\CriticalAlertNotification;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells administrators when a roster needs a person: late, held, failed or a rejected look-alike.
 * Everything else stays on the Roster Imports screen. One alert per kind per duty date, so a forged
 * message cannot use up the alert for a held export, and a failed alert never stops the import.
 */
final class RosterAlerts
{
    private const TABLE = 'roster_alert_deliveries';

    /** How long a sent alert stays remembered, as the cache key did. Every key names its duty date. */
    private const KEEP_DAYS = 2;

    private const MESSAGES = [
        'delayed' => 'Today\'s TeleStaff roster has not arrived. Members still see the last roster, marked as delayed.',
        'stale' => 'TeleStaff has stopped sending roster updates. Members still see the last roster, marked as delayed.',
        'needs_review' => 'A TeleStaff roster was held for review. Members still see the last good roster.',
        'failed' => 'A TeleStaff roster email could not be processed. Members still see the last good roster.',
        'rejected' => 'An email that looks like the TeleStaff roster failed the mail check and was not used. Members still see the last good roster.',
        'move_alerts_held' => 'An unusually large number of on-duty members changed in one TeleStaff roster, so no roster move alerts went out for it. Check the roster is right.',
    ];

    public function __construct(private readonly NotificationRecipientEvaluator $recipients) {}

    /** @param  string|null  $incident  Names one occurrence, when a kind can happen more than once in a duty date. */
    public function send(string $kind, string $dutyDate, ?string $incident = null): void
    {
        $message = self::MESSAGES[$kind] ?? null;
        if ($message === null) {
            return;
        }

        $this->deliver("daily_roster_alert:{$kind}:{$dutyDate}".($incident === null ? '' : ":{$incident}"), $message, '/admin/roster-imports');
    }

    /** The person TeleStaff has on a command seat cannot open the page until their account is set up. Once per person, seat and duty date. */
    public function seatAccountNotReady(string $name, string $seat, string $dutyDate, int $employeeProfileId): void
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate)->format('D, M j');

        $this->deliver(
            "daily_roster_alert:seat_account:{$dutyDate}:{$seat}:{$employeeProfileId}",
            "{$name} is on the {$seat} seat on the TeleStaff roster for {$date} but has not finished setting up a Hub account, so they cannot open the {$seat} page. Send an invitation from Personnel > Employees.",
            '/admin/employees',
        );
    }

    /** Usually a normal overtime or out-of-class ride, but a bad export could name anyone. Once per person, seat and duty date. */
    public function seatOnlyByRoster(string $name, string $seat, string $dutyDate, int $employeeProfileId): void
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate)->format('D, M j');

        $this->deliver(
            "daily_roster_alert:seat_only_roster:{$dutyDate}:{$seat}:{$employeeProfileId}",
            "{$name} can open the {$seat} page on {$date} only because the TeleStaff roster has them on that seat. If that looks wrong, check Roster Imports.",
            '/admin/roster-imports',
        );
    }

    private function deliver(string $key, string $message, string $path): void
    {
        try {
            if (! $this->claim($key)) {
                return;
            }

            $recipients = $this->recipients->recipients('system_alerts');
            foreach ($recipients as $recipient) {
                FilamentNotification::make()->warning()->title('Daily Roster')->body($message)->sendToDatabase($recipient);
            }
            Notification::send($recipients, new CriticalAlertNotification('Daily Roster', $message, 'daily_roster', url: url($path)));
        } catch (Throwable $exception) {
            $this->forget($key);
            report($exception);
        }
    }

    /**
     * Records the alert as sent, true only for the caller that wrote the row. It is kept in the database,
     * not the cache, because every deploy clears the cache and the same alert would go out again. The
     * savepoint keeps a failure here from spoiling a transaction the caller may have open.
     */
    private function claim(string $key): bool
    {
        $now = now();

        return DB::transaction(static function () use ($key, $now): bool {
            DB::table(self::TABLE)->where('expires_at', '<=', $now)->delete();

            return DB::table(self::TABLE)->insertOrIgnore([
                'alert_key' => $key,
                'sent_at' => $now,
                'expires_at' => $now->copy()->addDays(self::KEEP_DAYS),
            ]) === 1;
        });
    }

    /** The database may be what just failed, and that must not stop the import either. */
    private function forget(string $key): void
    {
        try {
            DB::table(self::TABLE)->where('alert_key', $key)->delete();
        } catch (Throwable) {
            // Nothing else to do: the original failure is about to be reported.
        }
    }
}
