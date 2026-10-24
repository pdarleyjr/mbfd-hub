<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\Channels\HubWebPushChannel;
use App\Services\Notifications\PushDeliveryOutcomeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Notification delivery plumbing: push device health, deferred push for devices in
 * back-off, and push cleanup when an account stops being usable. Kept apart from
 * AppServiceProvider on purpose.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Same VAPID client and report handler as the package; devices in a short back-off get the alert
        // when it ends instead of being skipped.
        $this->app->extend(
            WebPushChannel::class,
            static fn (WebPushChannel $channel): WebPushChannel => $channel instanceof HubWebPushChannel
                ? $channel
                : HubWebPushChannel::decorate($channel),
        );
    }

    public function boot(): void
    {
        Event::listen([NotificationSent::class, NotificationFailed::class], PushDeliveryOutcomeRecorder::class);

        // A disabled, deactivated or departed account keeps no push devices. The cleanup runs after the
        // account change commits and can never roll it back.
        User::updated(function (User $user): void {
            // Inside the "updated" event the model's original values are not yet synced, so read the new status directly.
            if ($user->wasChanged('account_status') && $user->getAttributes()['account_status'] !== AccountStatus::Active->value) {
                self::afterCommit(fn () => $user->pushSubscriptions()->delete());
            }
        });

        Employee::updated(function (Employee $employee): void {
            if ($employee->wasChanged('roster_status') && $employee->roster_status === 'departed') {
                self::afterCommit(fn () => User::query()
                    ->where('employee_profile_id', $employee->getKey())
                    ->get()
                    ->each(fn (User $user): mixed => $user->pushSubscriptions()->delete()));
            }
        });
    }

    /** Push housekeeping never fails the write it follows: run after commit and report, never throw. */
    private static function afterCommit(callable $cleanup): void
    {
        DB::afterCommit(static function () use ($cleanup): void {
            try {
                $cleanup();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
