<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\NewSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

final class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_channel_is_controlled_independently_and_email_defaults_off(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'NOTIFY-1',
            'name' => 'Notification Recipient',
            'city_email' => 'recipient@miamibeachfl.gov',
            'password' => 'notification-test-password',
        ]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id]);
        $user->updatePushSubscription(
            'https://push.example.test/subscription',
            str_repeat('a', 88),
            str_repeat('b', 24),
        );
        $notification = new NewSubmissionNotification(
            submissionType: 'station_request',
            title: 'Station request',
            body: 'A new request was submitted.',
        );

        self::assertSame([], $notification->via($user));

        UserNotificationSubscription::query()->create([
            'user_id' => $user->id,
            'event_key' => 'station_requests',
            'database_enabled' => true,
            'webpush_enabled' => false,
            'email_enabled' => false,
        ]);
        self::assertSame(['database'], $notification->via($user));

        $user->notificationSubscriptions()->update([
            'database_enabled' => false,
            'webpush_enabled' => true,
            'email_enabled' => true,
        ]);

        self::assertSame(
            [WebPushChannel::class, BudgetedMailChannel::class],
            $notification->via($user),
        );
    }
}
