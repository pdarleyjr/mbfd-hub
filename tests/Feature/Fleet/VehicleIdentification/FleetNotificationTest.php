<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\ApparatusIdentificationQuestion;
use App\Models\ApparatusInspection;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleIdentificationNotifier;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/** "Vehicle identification for Fleet": mirrors "Truck changed". */
final class FleetNotificationTest extends IdentificationTestCase
{
    private const EVENT = User::NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION;

    /** @return list<array<string, mixed>> answers that create a new vehicle record for an unlinked E2 */
    private function creatingAnswers(): array
    {
        return [$this->answer('vehicle_number', 'V-202'), $this->answer('vin', self::VIN_B)];
    }

    /** E2 not linked, and no record holds its number: a VIN is then enough to create one. */
    private function unitReadyForNewRecord(): void
    {
        $masterId = $this->unitMaster()->id;
        $this->unlink($this->unit);
        UnitMasterVehicle::query()->whereKey($masterId)->toBase()->update(['veh_number' => 'OLD-1']);
    }

    public function test_the_category_is_a_fleet_alert_with_database_push_and_email_on_by_default(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get(self::EVENT);

        $this->assertSame('vehicle_identification_reports', self::EVENT);
        $this->assertSame('Vehicle identification for Fleet', $category->label);
        $this->assertSame(NotificationCategoryRegistry::GROUP_FLEET, $category->group);
        $this->assertSame(['database' => true, 'webpush' => true, 'email' => true], $category->defaults);
        $this->assertTrue($category->senderWired);
        $this->assertArrayHasKey(self::EVENT, User::notificationPreferenceDefinitions());
        $this->assertSame(self::EVENT, User::preferenceKeyForSubmissionType(VehicleIdentificationNotifier::SUBMISSION_TYPE));
    }

    public function test_only_fleet_managers_are_eligible(): void
    {
        $manager = $this->fleetOperationsManager();
        $recipients = app(VehicleIdentificationNotifier::class)->recipients();

        // The manager and the super administrator who assigned the role; nobody else.
        $this->assertContains($manager->id, $recipients->pluck('id')->all());
        $this->assertCount(2, $recipients);
        $this->assertNotContains(self::EVENT, $this->member->visibleNotificationPreferenceKeys());
        $this->assertNotContains(self::EVENT, $this->fleetViewer()->visibleNotificationPreferenceKeys());
        $this->assertContains(self::EVENT, $manager->visibleNotificationPreferenceKeys());
    }

    public function test_a_record_created_from_a_checkout_reaches_fleet_through_inbox_and_email(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $viewer = $this->fleetViewer();
        $this->unitReadyForNewRecord();

        $this->submitCheckout($this->creatingAnswers())->assertCreated();

        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n, array $channels): bool => $channels === ['database', BudgetedMailChannel::class]);
        Notification::assertNotSentTo($viewer, NewSubmissionNotification::class);
        Notification::assertNotSentTo($this->member, NewSubmissionNotification::class);
        $notification = Notification::sent($manager, NewSubmissionNotification::class)->first();
        $payload = $notification->toDatabase($manager);
        $this->assertSame('New vehicle record to verify for E2', $payload['title']);
        $this->assertStringContainsString('V-202', $payload['body']);
        $this->assertStringEndsWith('/admin/apparatuses/'.$this->unit->id, $payload['actions'][0]['url']);
        $this->assertSame('New vehicle record to verify for E2', $notification->toBudgetedEmail($manager)['subject']);
    }

    public function test_each_channel_can_be_turned_off_for_one_account(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $silent = $this->fleetOperationsManager('second.manager@example.test');
        $silent->notificationSubscriptions()->updateOrCreate(['event_key' => self::EVENT], ['database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false]);
        $this->unitReadyForNewRecord();

        $this->submitCheckout($this->creatingAnswers())->assertCreated();

        Notification::assertSentTo($manager, NewSubmissionNotification::class);
        Notification::assertNotSentTo($silent, NewSubmissionNotification::class);
    }

    public function test_nothing_is_sent_for_an_answer_that_only_fills_a_blank(): void
    {
        $this->fleetOperationsManager('fleet.manager@example.test');

        $this->submitCheckout([$this->answer('year', '2022')])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_a_notification_failure_never_fails_the_checkout_that_raised_it(): void
    {
        $this->fleetOperationsManager('fleet.manager@example.test');
        $this->app->instance(VehicleIdentificationNotifier::class, new class extends VehicleIdentificationNotifier
        {
            public function reported(int $apparatusId, array $alerts): void
            {
                throw new RuntimeException('The mail service is down.');
            }
        });
        $this->unitReadyForNewRecord();

        $this->submitCheckout($this->creatingAnswers())->assertCreated();

        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(2, ApparatusIdentificationQuestion::query()->where('state', 'answered')->count());
        $this->assertNotNull($this->unit->fresh()->unit_master_vehicle_id);
    }
}
