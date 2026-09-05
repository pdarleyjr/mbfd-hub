<?php

declare(strict_types=1);

namespace Tests\Feature\DepartmentUpdates;

use App\Enums\AccountStatus;
use App\Jobs\DeliverDepartmentUpdateNotification;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use App\Models\DepartmentUpdateNotificationDelivery;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\DepartmentUpdateNotification;
use App\Services\DepartmentUpdates\DepartmentUpdateAudienceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class DepartmentUpdateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-09-05 14:00:00'));
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_homepage_only_publication_sends_no_notification(): void
    {
        $recipient = $this->member('1001', 'Firefighter');
        $update = $this->update(['send_in_app' => false, 'send_web_push' => false]);

        $this->runJob($update);

        Notification::assertNothingSent();
        self::assertNull($update->fresh()->notification_sent_at);
        self::assertTrue($recipient->isAuthenticationAllowed());
    }

    public function test_rank_audience_uses_linked_employee_rank_and_excludes_disabled_accounts(): void
    {
        $captain = $this->member('1002', 'Captain');
        $firefighter = $this->member('1003', 'Firefighter');
        $disabledCaptain = $this->member('1004', 'Captain', AccountStatus::Disabled);
        $update = $this->update(['audience' => 'officers']);

        $this->runJob($update);

        Notification::assertSentTo($captain, DepartmentUpdateNotification::class);
        Notification::assertNotSentTo($firefighter, DepartmentUpdateNotification::class);
        Notification::assertNotSentTo($disabledCaptain, DepartmentUpdateNotification::class);
    }

    public function test_administration_audience_uses_current_admin_entitlement(): void
    {
        $admin = $this->member('1005', 'Firefighter');
        $admin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $member = $this->member('1006', 'Firefighter');
        $update = $this->update(['audience' => 'administration']);

        $this->runJob($update);

        Notification::assertSentTo($admin, DepartmentUpdateNotification::class);
        Notification::assertNotSentTo($member, DepartmentUpdateNotification::class);
    }

    public function test_scheduled_delivery_waits_until_due_and_retry_does_not_duplicate_fanout(): void
    {
        $recipient = $this->member('1007', 'Firefighter');
        $update = $this->update([
            'audience' => 'driver_engineers',
            'audience_user_ids' => [$recipient->id],
            'publish_at' => now()->addHour(),
        ]);

        $this->runJob($update);
        Notification::assertNothingSent();

        Date::setTestNow(CarbonImmutable::parse('2026-09-05 16:00:00'));
        $this->runJob($update);
        $this->runJob($update);

        Notification::assertSentToTimes($recipient, DepartmentUpdateNotification::class, 1);
        self::assertNotNull($update->fresh()->notification_sent_at);
    }

    public function test_selected_members_are_limited_to_active_selected_accounts(): void
    {
        $selected = $this->member('1008', 'Firefighter');
        $other = $this->member('1009', 'Firefighter');
        $update = $this->update([
            'audience' => 'selected',
            'audience_user_ids' => [$selected->id],
        ]);

        $this->runJob($update);

        Notification::assertSentTo($selected, DepartmentUpdateNotification::class);
        Notification::assertNotSentTo($other, DepartmentUpdateNotification::class);
    }

    public function test_web_push_uses_the_existing_channel_only_for_an_active_subscription(): void
    {
        $recipient = $this->member('1010', 'Firefighter');
        $recipient->updatePushSubscription(
            'https://push.example.test/department-update',
            'test-public-key',
            'test-auth-token',
        );
        $update = $this->update(['send_in_app' => false, 'send_web_push' => true]);
        $notification = DepartmentUpdateNotification::fromUpdate($update);

        self::assertSame([WebPushChannel::class], $notification->via($recipient));

        $recipient->pushSubscriptions()->delete();
        self::assertSame([], $notification->via($recipient));
    }

    public function test_publisher_channels_are_intersected_with_member_preferences_and_never_add_email(): void
    {
        $recipient = $this->member('1011', 'Firefighter');
        $recipient->updatePushSubscription(
            'https://push.example.test/member-preference',
            'test-public-key',
            'test-auth-token',
        );
        $update = $this->update(['send_in_app' => true, 'send_web_push' => true]);
        $notification = DepartmentUpdateNotification::fromUpdate($update);
        $subscription = $recipient->notificationSubscriptions()
            ->where('event_key', User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES)
            ->firstOrFail();

        $subscription->update([
            'database_enabled' => false,
            'webpush_enabled' => true,
            'email_enabled' => true,
        ]);
        self::assertSame([WebPushChannel::class], $notification->via($recipient));

        $subscription->update([
            'database_enabled' => true,
            'webpush_enabled' => false,
            'email_enabled' => true,
        ]);
        self::assertSame(['database'], $notification->via($recipient));
    }

    public function test_pending_delivery_is_durable_and_can_be_recovered_after_dispatch_is_lost(): void
    {
        Queue::fake();
        $recipient = $this->member('1012', 'Firefighter');
        $update = $this->update();

        $this->runJob($update);

        $delivery = DepartmentUpdateNotificationDelivery::query()->sole();
        self::assertSame($recipient->id, $delivery->user_id);
        self::assertNotNull($update->fresh()->notification_prepared_at);
        self::assertNull($update->fresh()->notification_sent_at);
        Queue::assertPushed(DeliverDepartmentUpdateNotification::class);

        Notification::fake();
        (new DeliverDepartmentUpdateNotification($delivery->id))->handle();

        Notification::assertSentToTimes($recipient, DepartmentUpdateNotification::class, 1);
        self::assertNotNull($delivery->fresh()->delivered_at);
        self::assertNotNull($update->fresh()->notification_sent_at);
    }

    private function runJob(DepartmentUpdate $update): void
    {
        (new SendDepartmentUpdateNotification($update->id))->handle(
            app(DepartmentUpdateAudienceResolver::class),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function update(array $overrides = []): DepartmentUpdate
    {
        return DepartmentUpdate::query()->create(array_merge([
            'title' => 'Operational notice',
            'body' => '<p>Review this notice.</p>',
            'category' => 'operations',
            'priority' => 'important',
            'status' => 'published',
            'publish_at' => now()->subMinute(),
            'send_in_app' => true,
            'send_web_push' => false,
            'audience' => 'everyone',
            'author_id' => User::factory()->create()->id,
        ], $overrides));
    }

    private function member(string $employeeId, string $rank, AccountStatus $status = AccountStatus::Active): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => "Test {$rank}",
            'rank' => $rank,
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);

        $user = User::factory()->create([
            'account_status' => $status,
            'employee_profile_id' => $employee->id,
        ]);

        UserNotificationSubscription::query()->create([
            'user_id' => $user->id,
            'event_key' => User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES,
            'database_enabled' => true,
            'webpush_enabled' => true,
            'email_enabled' => false,
        ]);

        return $user;
    }
}
