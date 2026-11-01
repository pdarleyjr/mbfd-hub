<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\OperationalAlerts;

use App\Enums\AccountStatus;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Notifications\NotificationDecision;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Fleet\OperationalAlerts\Concerns\BuildsOperationalAlertFixtures;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

final class OperationalAlertServiceTest extends TestCase
{
    use BuildsNotificationPersonas;
    use BuildsOperationalAlertFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpAlertFixtures();
    }

    public function test_create_derives_the_unit_from_the_row_and_records_an_event(): void
    {
        $alert = $this->alerts()->create([
            'apparatus_id' => $this->r44->id,
            'unit_slug' => 'someone-elses-unit',
            'vehicle_number' => 'made-up',
            'station_id' => $this->station1->id,
            'category' => 'upcoming_swap',
            'title' => 'Upcoming Apparatus Changeout: Rescue 44',
            'message' => 'R44 changes vehicles.',
        ], $this->fleetManager());

        $this->assertSame('r44', $alert->unit_slug);
        $this->assertSame('R 44', $alert->unit_designation);
        $this->assertSame('17503', $alert->vehicle_number);
        $this->assertSame($this->station4->id, $alert->station_id);
        $this->assertSame('active', $alert->status);
        $this->assertSame(1, ApparatusFleetEvent::query()->where('alert_id', $alert->id)->where('event', 'alert_created')->count());
    }

    public function test_create_refuses_a_member_who_cannot_manage_fleet(): void
    {
        $this->expectException(AuthorizationException::class);

        try {
            $this->alerts()->create([
                'apparatus_id' => $this->r44->id, 'category' => 'general', 'title' => 'Notice', 'message' => 'Text',
            ], $this->rosterMember());
        } finally {
            $this->assertSame(0, ApparatusOperationalAlert::query()->count());
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidCreations(): array
    {
        return [
            'unknown category' => [['category' => 'rumor'], 'category'],
            'title too long' => [['title' => str_repeat('x', 181)], 'title'],
            'message too long' => [['message' => str_repeat('x', 1001)], 'message'],
            'unknown unit' => [['apparatus_id' => 999999], 'apparatus_id'],
            'date with a time' => [['scheduled_date' => '2026-11-01 15:00'], 'scheduled_date'],
            'unknown window' => [['scheduled_window' => 'midnight'], 'scheduled_window'],
            'title that is only markup' => [['title' => '<b></b>'], 'title'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidCreations')]
    public function test_create_validates_its_fields(array $overrides, string $field): void
    {
        try {
            $this->alert(null, $overrides);
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_a_ticket_on_another_truck_cannot_be_linked(): void
    {
        $other = app(ApparatusServiceTicketWorkflowService::class)->recordFleetNotice($this->e1, $this->ticketNotice())->ticket;

        $this->expectException(ValidationException::class);
        $this->alert($this->r44, ['apparatus_service_ticket_id' => $other->id]);
    }

    public function test_the_same_dedupe_key_returns_the_existing_row(): void
    {
        $first = $this->alert(null, ['dedupe_key' => 'one-notice']);
        $second = $this->alert(null, ['dedupe_key' => 'one-notice', 'title' => 'A different title']);

        $this->assertTrue($first->is($second));
        $this->assertSame('Upcoming Apparatus Changeout', $second->title);
        $this->assertSame(1, ApparatusOperationalAlert::query()->count());
    }

    public function test_a_unit_has_one_planned_changeout_and_the_same_dedupe_key_still_returns_its_row(): void
    {
        $first = $this->alert(null, ['dedupe_key' => 'fleet-notice']);

        $this->assertTrue($first->is($this->alert(null, ['dedupe_key' => 'fleet-notice'])));
        foreach ([[], ['dedupe_key' => 'a-different-notice']] as $overrides) {
            try {
                $this->alert(null, $overrides);
                $this->fail('Expected a refusal.');
            } catch (ValidationException $exception) {
                $this->assertSame(['R 44 already has a planned changeout. Edit that one, or close it first.'], $exception->errors()['category']);
            }
        }
        $this->assertSame(1, ApparatusOperationalAlert::query()->count());

        $this->alert($this->e1);
        $this->notice();
        $this->assertSame(3, ApparatusOperationalAlert::query()->count(), 'another unit and other kinds of alert are unaffected');

        $this->alerts()->close($first, $this->fleetManager());
        $this->assertSame('active', $this->alert()->status, 'closing it frees the unit');
    }

    public function test_changing_an_alert_into_a_second_planned_changeout_is_refused_and_editing_the_plan_is_not(): void
    {
        $manager = $this->fleetManager();
        $swap = $this->alert();
        $notice = $this->notice();

        try {
            $this->alerts()->update($notice, ['category' => 'upcoming_swap'], $manager);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $exception) {
            $this->assertSame(['R 44 already has a planned changeout. Edit that one, or close it first.'], $exception->errors()['category']);
        }
        $this->assertSame('general', $notice->fresh()->category);

        $this->assertSame('Edited plan', $this->alerts()->update($swap, ['title' => 'Edited plan'], $manager)->title);
    }

    public function test_the_database_itself_holds_one_active_planned_changeout_per_unit(): void
    {
        $swap = $this->alert();
        $row = collect($swap->getAttributes())->except(['id', 'dedupe_key', 'created_at', 'updated_at'])->all();

        $this->expectException(QueryException::class);
        DB::table('apparatus_operational_alerts')->insert($row);
    }

    public function test_html_never_survives_and_ordinary_less_than_signs_do(): void
    {
        $alert = $this->alert(null, [
            'title' => '<script>alert(1)</script>Notice <<b>img src=x>',
            'message' => 'Keep the pressure < 50 psi. <a href="https://example.test">Open</a>',
        ]);

        $this->assertStringNotContainsString('<script', $alert->title);
        $this->assertStringNotContainsString('<img', $alert->title);
        $this->assertSame('Keep the pressure < 50 psi. Open', $alert->message);
    }

    public function test_update_records_only_what_changed_and_a_repeat_records_nothing(): void
    {
        $alert = $this->alert();
        $manager = $this->fleetManager();

        $updated = $this->alerts()->update($alert, ['title' => 'New title', 'apparatus_id' => $this->e1->id, 'unit_slug' => 'e1'], $manager);
        $this->assertSame('New title', $updated->title);
        $this->assertSame($this->r44->id, $updated->apparatus_id);
        $this->assertSame('r44', $updated->unit_slug);

        $this->alerts()->update($alert, ['title' => 'New title'], $manager);
        $events = ApparatusFleetEvent::query()->where('alert_id', $alert->id)->where('event', 'alert_updated')->get();
        $this->assertCount(1, $events);
        $this->assertSame(['title' => ['from' => 'Upcoming Apparatus Changeout', 'to' => 'New title']], $events->sole()->data['changed']);
    }

    public function test_only_an_active_alert_can_be_edited(): void
    {
        $manager = $this->fleetManager();
        $alert = $this->alert();
        $this->alerts()->close($alert, $manager);

        $this->expectException(ValidationException::class);
        $this->alerts()->update($alert, ['title' => 'Too late'], $manager);
    }

    public function test_cancel_and_close_keep_history_and_a_repeat_changes_nothing(): void
    {
        $manager = $this->fleetManager();
        $alert = $this->alert();

        $cancelled = $this->alerts()->cancel($alert, $manager, 'Fleet changed its mind');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Fleet changed its mind', $cancelled->close_note);
        $this->assertNotNull($cancelled->closed_at);

        $closedAt = $cancelled->closed_at;
        $again = $this->alerts()->close($alert, $manager, 'Second click');
        $this->assertSame('cancelled', $again->status);
        $this->assertSame('Fleet changed its mind', $again->close_note);
        $this->assertTrue($closedAt->equalTo($again->closed_at));
        $this->assertSame(1, ApparatusFleetEvent::query()->where('alert_id', $alert->id)->whereIn('event', ['alert_cancelled', 'alert_closed'])->count());
        $this->assertDatabaseHas('apparatus_operational_alerts', ['id' => $alert->id]);
    }

    public function test_managing_an_alert_needs_fleet_rights(): void
    {
        $alert = $this->alert();
        $member = $this->rosterMember();

        foreach ([
            fn () => $this->alerts()->update($alert, ['title' => 'Nope'], $member),
            fn () => $this->alerts()->cancel($alert, $member),
            fn () => $this->alerts()->close($alert, $member),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a refusal.');
            } catch (AuthorizationException) {
                $this->assertSame('active', $alert->fresh()->status);
            }
        }
    }

    public function test_complete_from_changeout_completes_once_and_never_touches_the_ticket(): void
    {
        $ticket = app(ApparatusServiceTicketWorkflowService::class)->recordFleetNotice($this->r44, $this->ticketNotice())->ticket;
        $alert = $this->alert(null, ['apparatus_service_ticket_id' => $ticket->id]);
        $changeout = $this->changeout($alert);
        $before = ApparatusServiceTicket::query()->findOrFail($ticket->id)->getAttributes();

        $this->assertTrue($this->alerts()->completeFromChangeout($alert, $changeout, null));
        $this->assertSame('completed', $alert->status);
        $this->assertSame($changeout->id, $alert->completed_by_changeout_id);
        $this->assertNotNull($alert->closed_at);

        $this->assertTrue($this->alerts()->completeFromChangeout($alert, $changeout, null), 'a repeat is idempotent');
        $this->assertSame(1, ApparatusFleetEvent::query()->where('alert_id', $alert->id)->where('event', 'alert_completed')->count());
        $this->assertSame($before, ApparatusServiceTicket::query()->findOrFail($ticket->id)->getAttributes());
    }

    public function test_complete_from_changeout_refuses_any_mismatch_and_changes_nothing(): void
    {
        $alert = $this->alert();
        $otherAlert = $this->notice(['title' => 'Another alert']);
        $refused = [
            'another unit' => $this->changeout($alert, ['unit_slug' => 'e1']),
            'not finished' => $this->changeout($alert, ['status' => 'old_state']),
            'tied to another alert' => $this->changeout($otherAlert),
        ];

        foreach ($refused as $case => $changeout) {
            $this->assertFalse($this->alerts()->completeFromChangeout($alert, $changeout, null), $case);
            $fresh = $alert->fresh();
            $this->assertSame('active', $fresh->status, $case);
            $this->assertNull($fresh->completed_by_changeout_id, $case);
        }
    }

    public function test_a_second_changeout_cannot_claim_an_alert_another_completed_and_a_cancelled_alert_stays_cancelled(): void
    {
        $alert = $this->alert();
        $first = $this->changeout($alert);
        $second = $this->changeout($alert);
        $this->assertTrue($this->alerts()->completeFromChangeout($alert, $first, null));
        $this->assertFalse($this->alerts()->completeFromChangeout($alert, $second, null));
        $this->assertSame($first->id, $alert->fresh()->completed_by_changeout_id);

        $cancelled = $this->alert();
        $this->alerts()->cancel($cancelled, $this->fleetManager());
        $this->assertFalse($this->alerts()->completeFromChangeout($cancelled, $this->changeout($cancelled), null));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_active_for_unit_lists_only_that_units_active_alerts(): void
    {
        $this->alerts()->close($this->alert(), $this->fleetManager());
        $maintenance = $this->alert(null, ['category' => 'maintenance']);
        $swap = $this->alert();
        $this->alert($this->e1);

        $this->assertSame([$swap->id, $maintenance->id], $this->alerts()->activeForUnit('r44')->pluck('id')->all());
    }

    public function test_a_notification_goes_out_once_to_active_members_with_a_link_to_the_station(): void
    {
        $member = $this->rosterMember();
        $inactive = $this->member(['account_status' => AccountStatus::Disabled]);

        $alert = $this->alert(null, ['send_notification' => true, 'dedupe_key' => 'notified']);
        $this->assertNotNull($alert->notified_at);

        Notification::assertSentToTimes($member, NewSubmissionNotification::class, 1);
        Notification::assertNotSentTo($inactive, NewSubmissionNotification::class);
        $payload = $this->sentTo($member)->toDatabase($member);
        $this->assertSame($alert->title, $payload['title']);
        $this->assertSame($alert->message, $payload['body']);
        $this->assertSame("/daily/stations/{$this->station4->id}", $payload['actions'][0]['url']);

        $notifiedAt = $alert->notified_at;
        $this->alert(null, ['send_notification' => true, 'dedupe_key' => 'notified']);
        $this->alerts()->update($alert, ['title' => 'Edited', 'send_notification' => true], $this->fleetManager());
        Notification::assertSentToTimes($member, NewSubmissionNotification::class, 1);
        $this->assertTrue($notifiedAt->equalTo($alert->fresh()->notified_at));
    }

    public function test_no_notification_unless_it_was_asked_for_and_turning_it_on_later_sends_it(): void
    {
        $member = $this->rosterMember();
        $alert = $this->alert();
        Notification::assertNothingSent();
        $this->assertNull($alert->notified_at);

        $this->alerts()->update($alert, ['send_notification' => true], $this->fleetManager());
        Notification::assertSentToTimes($member, NewSubmissionNotification::class, 1);
    }

    public function test_the_category_is_the_bell_only_by_default_even_with_a_phone_enrolled(): void
    {
        $member = $this->rosterMember();
        $this->addPushDevice($member);

        $decision = app(NotificationRecipientEvaluator::class)->decide($member, User::NOTIFICATION_PREFERENCE_FLEET_OPERATIONAL_ALERTS);

        $this->assertSame(['database'], $decision->channels);
        $this->assertSame(NotificationDecision::PREFERENCE_OFF, $decision->denials['webpush']);
        $this->assertSame('active_member', $decision->rule);
    }

    private function sentTo(User $user): NewSubmissionNotification
    {
        return Notification::sent($user, NewSubmissionNotification::class)->first();
    }

    /** @return array<string, string> */
    private function ticketNotice(): array
    {
        return [
            'client_submission_id' => (string) Str::uuid(),
            'category' => 'repair_mechanical',
            'title' => 'Exhaust repair',
            'description' => 'Fleet advised that the exhaust will be repaired.',
            'priority' => 'routine',
            'requester_name_snapshot' => 'Fleet (email)',
        ];
    }
}
