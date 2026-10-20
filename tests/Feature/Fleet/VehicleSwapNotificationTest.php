<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Enums\AccountStatus;
use App\Filament\Pages\NotificationSettings;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleSwapNotifier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

final class VehicleSwapNotificationTest extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    private const EVENT = User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function setPreference(User $user, bool $database, bool $email, bool $webpush = false): void
    {
        $user->notificationSubscriptions()->updateOrCreate(['event_key' => self::EVENT], [
            'database_enabled' => $database,
            'webpush_enabled' => $webpush,
            'email_enabled' => $email,
        ]);
    }

    public function test_a_new_report_reaches_fleet_staff_through_the_existing_inbox_and_email_channels(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $swap = $this->heldRequest();

        Notification::assertSentTo(
            $manager,
            NewSubmissionNotification::class,
            fn (NewSubmissionNotification $notification, array $channels): bool => $channels === ['database', BudgetedMailChannel::class],
        );

        $payload = $this->sentNotification($manager)->toDatabase($manager);
        $this->assertSame('Vehicle swap reported on E2', $payload['title']);
        $this->assertStringContainsString('V-202', $payload['body']);
        $this->assertStringContainsString('V-303', $payload['body']);
        $this->assertStringContainsString('Swap Reporter', $payload['body']);
        $this->assertSame('/admin/vehicle-swap-requests', $payload['actions'][0]['url']);
        $mail = $this->sentNotification($manager)->toBudgetedEmail($manager);
        $this->assertSame('Vehicle swap reported on E2', $mail['subject']);
        $this->assertSame('pending', $swap->status);
    }

    public function test_members_and_view_only_fleet_staff_are_not_notified(): void
    {
        $viewer = $this->fleetViewer();
        $this->heldRequest();

        Notification::assertNotSentTo($viewer, NewSubmissionNotification::class);
        Notification::assertNotSentTo($this->member, NewSubmissionNotification::class);
    }

    public function test_each_channel_can_be_turned_off_for_one_account_without_the_others(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $emailOnly = $this->fleetOperationsManager('second.manager@example.test');
        $silent = $this->fleetOperationsManager('third.manager@example.test');
        $this->setPreference($manager, database: false, email: true);
        $this->setPreference($emailOnly, database: true, email: false);
        $this->setPreference($silent, database: false, email: false);

        $this->heldRequest();

        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn ($n, array $channels): bool => $channels === [BudgetedMailChannel::class]);
        Notification::assertSentTo($emailOnly, NewSubmissionNotification::class, fn ($n, array $channels): bool => $channels === ['database']);
        Notification::assertNotSentTo($silent, NewSubmissionNotification::class);
    }

    public function test_a_choice_to_turn_the_alert_off_is_never_overwritten(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->setPreference($manager, database: false, email: false);

        UserNotificationSubscription::ensureVehicleSwapForUser((int) $manager->id);

        $row = $manager->notificationSubscriptions()->where('event_key', self::EVENT)->sole();
        $this->assertFalse($row->database_enabled);
        $this->assertFalse($row->email_enabled);
    }

    public function test_the_manager_turns_the_alert_off_and_on_from_notification_settings(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->actingAs($manager);

        Livewire::test(NotificationSettings::class)
            ->assertFormFieldExists(self::EVENT.'.database')
            ->assertFormFieldExists(self::EVENT.'.webpush')
            ->assertFormFieldExists(self::EVENT.'.email')
            ->assertFormSet([self::EVENT => ['database' => true, 'webpush' => false, 'email' => true]])
            ->fillForm([self::EVENT => ['database' => false, 'webpush' => false, 'email' => false]])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = $manager->notificationSubscriptions()->where('event_key', self::EVENT)->sole();
        $this->assertFalse($row->database_enabled);
        $this->assertFalse($row->email_enabled);
        $this->heldRequest();
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);

        Livewire::test(NotificationSettings::class)
            ->fillForm([self::EVENT => ['database' => true, 'webpush' => false, 'email' => true]])
            ->call('save');

        Notification::fake();
        $this->heldRequest('V-909');
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn ($n, array $channels): bool => $channels === ['database', BudgetedMailChannel::class]);
    }

    public function test_the_setting_is_offered_only_to_members_who_can_decide_swaps(): void
    {
        $support = User::factory()->create(['account_status' => AccountStatus::Active]);
        $support->givePermissionTo(Permission::findOrCreate('admin.support.view', 'web'));
        $this->actingAs($support);

        Livewire::test(NotificationSettings::class)
            ->assertSuccessful()
            ->assertFormFieldExists(User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES.'.email')
            ->assertFormFieldDoesNotExist(self::EVENT.'.email')
            ->call('save');

        $this->assertDatabaseMissing('user_notification_subscriptions', ['user_id' => $support->id, 'event_key' => self::EVENT]);
    }

    public function test_a_fleet_operations_manager_can_open_notification_settings(): void
    {
        $this->actingAs($this->fleetOperationsManager());
        $this->assertTrue(NotificationSettings::canAccess());

        $this->actingAs($this->member);
        $this->assertFalse(NotificationSettings::canAccess());
    }

    private function sentNotification(User $user): NewSubmissionNotification
    {
        return Notification::sent($user, NewSubmissionNotification::class)->first();
    }

    public function test_a_notification_failure_never_fails_the_checkout_that_raised_it(): void
    {
        $this->fleetOperationsManager('fleet.manager@example.test');
        $this->app->instance(VehicleSwapNotifier::class, new class extends VehicleSwapNotifier
        {
            public function reported(int $requestId): void
            {
                throw new RuntimeException('The mail service is down.');
            }
        });

        $this->postJson($this->submissionUrl(), $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'unknown'], withFinding: true))
            ->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap');

        $this->assertSame(1, ApparatusVehicleSwapRequest::query()->where('status', 'pending')->count());
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_the_queue_count_matches_the_reports_waiting(): void
    {
        $this->heldRequest();
        $this->assertSame(1, ApparatusVehicleSwapRequest::query()->where('status', 'pending')->count());
    }
}
