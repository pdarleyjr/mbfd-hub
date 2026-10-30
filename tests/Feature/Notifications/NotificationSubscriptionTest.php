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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function stationRequestNotification(): NewSubmissionNotification
    {
        return new NewSubmissionNotification(
            submissionType: 'station_request',
            title: 'Station request',
            body: 'A new request was submitted.',
        );
    }

    /** An active member who may open station requests, with a device and a city email. */
    private function stationReviewer(): User
    {
        $employee = Employee::query()->create([
            'employee_id' => 'NOTIFY-1',
            'name' => 'Notification Recipient',
            'city_email' => 'recipient@miamibeachfl.gov',
            'password' => 'notification-test-password',
        ]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => 'NOTIFY-1', 'account_status' => 'active']);
        $user->givePermissionTo(
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.stations.view', 'web'),
        );
        $user->updatePushSubscription('https://push.example.test/subscription', str_repeat('a', 88), str_repeat('b', 24));

        return $user->fresh();
    }

    public function test_each_channel_is_controlled_independently_and_the_bell_and_phone_are_on_by_default(): void
    {
        $user = $this->stationReviewer();
        $notification = $this->stationRequestNotification();

        // Never chosen anything: the category default is the bell plus the phone (this member has a device).
        self::assertSame(['database', WebPushChannel::class], $notification->via($user));

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

        $user->notificationSubscriptions()->update(['database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false]);
        self::assertSame([], $notification->via($user));
    }

    public function test_a_saved_preference_never_makes_an_ineligible_member_a_recipient(): void
    {
        $outsider = User::factory()->create(['account_status' => 'active']);
        $outsider->notificationSubscriptions()->create([
            'event_key' => 'station_requests',
            'database_enabled' => true,
            'webpush_enabled' => true,
            'email_enabled' => true,
        ]);
        $outsider->updatePushSubscription('https://push.example.test/outsider', str_repeat('a', 88), str_repeat('b', 24));

        $notification = $this->stationRequestNotification();

        self::assertSame([], $notification->via($outsider));
        self::assertFalse($notification->shouldSend($outsider, 'database'));
        self::assertFalse($notification->shouldSend($outsider, WebPushChannel::class));
    }

    public function test_a_disabled_account_receives_nothing_even_when_it_still_holds_the_permission(): void
    {
        $user = $this->stationReviewer();
        $user->forceFill(['account_status' => 'disabled'])->save();

        self::assertSame([], $this->stationRequestNotification()->via($user->fresh()));
    }
}
