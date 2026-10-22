<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\IssueMessageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class DefectCorrectiveActionServiceTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private DefectCorrectiveActionService $service;

    private Apparatus $rig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->service = app(DefectCorrectiveActionService::class);
        $this->rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
    }

    private function eventsOf(string $type): int
    {
        return ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', $type)->count();
    }

    /** @param array<string, mixed> $overrides */
    private function resolveData(array $overrides = []): array
    {
        return [
            'resolution_type' => 'replacement_installed',
            'resolution_notes' => 'Battery issued from stock room.',
            ...$overrides,
        ];
    }

    public function test_an_ordinary_member_cannot_run_any_corrective_action(): void
    {
        $member = $this->member('Sam Member');

        foreach ([
            fn () => $this->service->acknowledge($this->defect->id, $member),
            fn () => $this->service->resolve($this->defect->id, $member, $this->resolveData()),
            fn () => $this->service->dismiss($this->defect->id, $member, ['reason' => 'No longer needed.']),
            fn () => $this->service->classifyImpact($this->defect->id, $member, ['operational_impact' => 'non_blocking']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A member must not run corrective actions.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertSame('reported', $this->defect->fresh()->action_status);
    }

    public function test_acknowledge_records_the_actor_and_a_member_visible_message_the_participant_can_read(): void
    {
        $confirmer = $this->member('Casey Confirmer');
        $this->participant($this->defect, $confirmer, 'confirmed', $this->inspection);

        $defect = $this->service->acknowledge($this->defect->id, $this->admin, [
            'member_message' => 'Logistics has your report and is sourcing a replacement.',
            'internal_note' => 'Supply says the vendor is backordered.',
        ]);

        $this->assertSame('acknowledged', $defect->action_status);
        $this->assertSame($this->admin->id, $defect->acknowledged_by_user_id);
        $this->assertNotNull($defect->acknowledged_at);
        $this->assertFalse($defect->resolved);
        $event = ApparatusDefectEvent::query()->sole();
        $this->assertSame('acknowledged', $event->event_type);
        $this->assertSame('reported', $event->from_status);
        $this->assertSame($this->admin->id, $event->actor_user_id);

        $thread = app(IssueMessageService::class)->threadFor($confirmer, $defect);
        $this->assertCount(1, $thread);
        $this->assertSame('Logistics has your report and is sourcing a replacement.', $thread->first()->body);
        $this->assertSame(ApparatusIssueMessage::VISIBILITY_MEMBER, $thread->first()->visibility);
        Notification::assertSentTo($confirmer, InspectionEventNotification::class);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_text_typed_on_an_already_acknowledged_issue_is_kept_as_an_update(): void
    {
        $this->service->acknowledge($this->defect->id, $this->admin);
        $this->service->acknowledge($this->defect->id, $this->admin, ['member_message' => 'Parts are on order.']);

        $this->assertSame(['acknowledged', 'action_updated'], ApparatusDefectEvent::query()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame('acknowledged', $this->defect->fresh()->action_status);
        $this->assertSame('Parts are on order.', ApparatusIssueMessage::query()->sole()->body);
    }

    public function test_an_idempotency_key_only_guards_the_operation_it_was_issued_for(): void
    {
        $this->service->acknowledge($this->defect->id, $this->admin, [], 'form-load-1');
        $resolved = $this->service->resolve($this->defect->id, $this->admin, $this->resolveData(), 'form-load-1');

        $this->assertTrue($resolved->resolved);
        $this->assertSame(['acknowledged', 'resolved'], ApparatusDefectEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_only_a_real_integer_assignee_is_a_person(): void
    {
        $badge = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);

        $text = $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['assigned_to' => (string) $badge->id],
        ]);
        $this->assertSame((string) $badge->id, $text->action_details['assigned_to']);
        $this->assertSame(0, \App\Models\ApparatusDefectParticipant::query()->where('user_id', $badge->id)->count());

        $person = $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['assigned_to' => $badge->id],
        ]);
        $this->assertSame($badge->id, $person->action_details['assigned_to']);
        $this->assertSame('assigned', \App\Models\ApparatusDefectParticipant::query()->where('user_id', $badge->id)->sole()->enrollment_source);
    }

    public function test_a_suspended_administrator_cannot_run_corrective_actions(): void
    {
        $this->admin->forceFill(['account_status' => \App\Enums\AccountStatus::Disabled])->save();

        $this->expectException(AuthorizationException::class);
        $this->service->acknowledge($this->defect->id, $this->admin->fresh());
    }

    public function test_acknowledging_twice_adds_nothing(): void
    {
        $this->service->acknowledge($this->defect->id, $this->admin);
        $this->service->acknowledge($this->defect->id, $this->admin);

        $this->assertSame(1, ApparatusDefectEvent::query()->count());
    }

    public function test_replacement_available_stores_instructions_actor_and_time_and_keeps_the_issue_open(): void
    {
        $defect = $this->service->markReplacementAvailable($this->defect->id, $this->admin, [
            'details' => ['pickup_instructions' => 'Pick up at Logistics, window 2.', 'assigned_department' => 'Logistics'],
            'member_message' => 'A replacement battery is ready at Logistics.',
        ]);

        $this->assertSame('replacement_available', $defect->action_status);
        $this->assertFalse($defect->resolved);
        $this->assertSame('in_progress', $defect->status);
        $this->assertSame('Pick up at Logistics, window 2.', $defect->action_details['pickup_instructions']);
        $this->assertSame('Logistics', $defect->action_details['assigned_department']);
        $this->assertNotNull($defect->acknowledged_at);
        $event = ApparatusDefectEvent::query()->sole();
        $this->assertSame('replacement_available', $event->event_type);
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertNotNull($event->created_at);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, function (InspectionEventNotification $notification): bool {
            return str_contains($notification->body, 'Replacement available') && str_contains($notification->body, 'A replacement battery is ready');
        });
    }

    public function test_purchasing_details_are_optional_and_validated_only_when_given(): void
    {
        $bare = $this->service->updateAction($this->defect->id, $this->admin, ['action_status' => 'action_pending']);
        $this->assertSame('action_pending', $bare->action_status);
        $this->assertNull($bare->action_details);

        $detailed = $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => [
                'parts_ordered' => true,
                'vendor_reference' => 'PO-1187',
                'expected_delivery' => '2026-10-30',
                'repair_appointment' => '2026-11-02 09:30',
            ],
        ]);
        $this->assertTrue($detailed->action_details['parts_ordered']);
        $this->assertSame('PO-1187', $detailed->action_details['vendor_reference']);
        $this->assertSame('2026-10-30', $detailed->action_details['expected_delivery']);
        $this->assertStringStartsWith('2026-11-02T09:30', $detailed->action_details['repair_appointment']);

        // A blank value clears one key and leaves the others.
        $cleared = $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['vendor_reference' => ''],
        ]);
        $this->assertArrayNotHasKey('vendor_reference', $cleared->action_details);
        $this->assertTrue($cleared->action_details['parts_ordered']);

        $this->expectException(ValidationException::class);
        $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['expected_delivery' => 'next whenever'],
        ]);
    }

    public function test_an_unchanged_update_is_a_no_op(): void
    {
        $this->service->scheduleRepair($this->defect->id, $this->admin, ['details' => ['assigned_department' => 'Fleet']]);
        $this->service->scheduleRepair($this->defect->id, $this->admin, ['details' => ['assigned_department' => 'Fleet']]);

        $this->assertSame(1, $this->eventsOf('repair_scheduled'));
    }

    public function test_resolve_requires_a_resolution_type_and_notes(): void
    {
        foreach ([
            ['resolution_notes' => 'Done.'],
            ['resolution_type' => 'replacement_installed'],
            ['resolution_type' => 'replacement_installed', 'resolution_notes' => '   '],
            ['resolution_type' => 'magic', 'resolution_notes' => 'Done.'],
        ] as $data) {
            try {
                $this->service->resolve($this->defect->id, $this->admin, $data);
                $this->fail('Resolution without a valid type and notes must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse($this->defect->fresh()->resolved);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }

    public function test_resolve_closes_the_issue_and_notifies_the_reporter_and_participants_exactly_once(): void
    {
        $confirmer = $this->member('Casey Confirmer');
        $this->participant($this->defect, $confirmer, 'confirmed', $this->inspection);

        $defect = $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());

        $this->assertTrue($defect->resolved);
        $this->assertSame('resolved', $defect->status);
        $this->assertSame('resolved', $defect->action_status);
        $this->assertSame($this->admin->id, $defect->resolved_by_user_id);
        $this->assertSame('replacement_installed', $defect->resolution_type);
        $this->assertSame('Battery issued from stock room.', $defect->resolution_notes);
        $this->assertNotNull($defect->resolved_at);
        $this->assertSame(1, $this->eventsOf('resolved'));
        $this->assertSame(2, DB::table('inspection_notification_deliveries')->count());
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentToTimes($confirmer, InspectionEventNotification::class, 1);
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_a_retry_with_the_same_idempotency_key_adds_no_second_event_message_or_notification(): void
    {
        $data = $this->resolveData(['member_message' => 'The battery is back in the radio.']);

        $first = $this->service->resolve($this->defect->id, $this->admin, $data, 'resolve-1');
        $second = $this->service->resolve($this->defect->id, $this->admin, $data, 'resolve-1');

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->resolved);
        $this->assertSame(1, ApparatusDefectEvent::query()->count());
        $this->assertSame(1, ApparatusIssueMessage::query()->count());
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        $this->assertSame(1, DB::table('inspection_notification_deliveries')->count());
    }

    public function test_the_same_key_on_a_different_defect_is_independent(): void
    {
        $other = $this->openDefect($this->rig, $this->inspection, $this->reporter, 'Spare SCBA cylinder');

        $this->service->acknowledge($this->defect->id, $this->admin, [], 'shared-key');
        $this->service->acknowledge($other->id, $this->admin, [], 'shared-key');

        $this->assertSame(2, ApparatusDefectEvent::query()->count());
    }

    public function test_two_administrators_resolving_produce_one_resolved_event_and_one_set_of_notifications(): void
    {
        $second = $this->reviewer('admin', 'Second Admin');

        $this->service->resolve($this->defect->id, $this->admin, $this->resolveData(), 'admin-a');
        $current = $this->service->resolve($this->defect->id, $second, $this->resolveData(['resolution_notes' => 'Also fixed by me.']), 'admin-b');

        $this->assertSame($this->admin->id, $current->resolved_by_user_id);
        $this->assertSame('Battery issued from stock room.', $current->resolution_notes);
        $this->assertSame(1, $this->eventsOf('resolved'));
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    public function test_reopen_keeps_the_same_row_and_every_event(): void
    {
        $resolved = $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());
        $reopened = $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'The battery was the wrong model.']);

        $this->assertSame($resolved->id, $reopened->id);
        $this->assertFalse($reopened->resolved);
        $this->assertNull($reopened->resolved_at);
        $this->assertNull($reopened->resolution_type);
        $this->assertNull($reopened->resolution_notes);
        $this->assertNull($reopened->resolved_by_user_id);
        $this->assertSame('reported', $reopened->action_status);
        $this->assertSame(['resolved', 'reopened'], ApparatusDefectEvent::query()->orderBy('id')->pluck('event_type')->all());
        $reopenEvent = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        $this->assertSame('Battery issued from stock room.', $reopenEvent->details['previous_resolution_notes']);
        $this->assertSame('The battery was the wrong model.', $reopenEvent->details['reason']);

        // Reopening an open issue is a no-op, not a second event.
        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'Again.']);
        $this->assertSame(2, ApparatusDefectEvent::query()->count());
    }

    public function test_reopen_goes_back_to_acknowledged_when_logistics_had_already_acknowledged(): void
    {
        $this->service->acknowledge($this->defect->id, $this->admin);
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => 'Reported by mistake.']);

        $reopened = $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'It was real.']);

        $this->assertSame('acknowledged', $reopened->action_status);
    }

    public function test_a_closed_issue_cannot_be_reopened_over_a_newer_open_issue_for_the_same_item(): void
    {
        $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());
        ApparatusDefect::query()->create([
            'apparatus_id' => $this->rig->id,
            'compartment' => 'Cab',
            'item' => 'Portable radio battery',
            'status' => 'open',
            'issue_type' => 'missing',
            'recurrence_of_defect_id' => $this->defect->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'Gone again.']);
    }

    public function test_dismiss_requires_a_reason_and_closes_without_a_resolution(): void
    {
        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => '']);
            $this->fail('Dismissal needs a reason.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $defect = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => 'Item was never assigned to this rig.']);

        $this->assertSame('dismissed', $defect->action_status);
        $this->assertTrue($defect->resolved);
        $this->assertNull($defect->resolution_type);
        $this->assertSame('Item was never assigned to this rig.', $defect->resolution_notes);
        $this->assertSame(1, $this->eventsOf('dismissed'));
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());

        $this->expectException(ValidationException::class);
        $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());
    }

    public function test_verify_correction_needs_a_latest_observation_that_appears_corrected(): void
    {
        try {
            $this->service->verifyCorrection($this->defect->id, $this->admin);
            $this->fail('There is nothing to verify yet.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, ApparatusDefectEvent::query()->count());

        $nextDay = $this->inspectionBy($this->rig, $this->reporter, '2026-10-20 09:00:00');
        $this->observe($this->defect, $nextDay, 'appears_corrected', $this->reporter);

        $defect = $this->service->verifyCorrection($this->defect->id, $this->admin, ['notes' => 'Radio checked: battery present.']);

        $this->assertTrue($defect->resolved);
        $this->assertSame('item_located', $defect->resolution_type);
        $this->assertSame('Radio checked: battery present.', $defect->resolution_notes);
        $event = ApparatusDefectEvent::query()->sole();
        $this->assertSame('correction_verified', $event->event_type);
        $this->assertSame($nextDay->id, $event->apparatus_inspection_id);
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    public function test_verify_correction_is_refused_when_the_latest_observation_is_still_a_finding(): void
    {
        $nextDay = $this->inspectionBy($this->rig, $this->reporter, '2026-10-20 09:00:00');
        $this->observe($this->defect, $nextDay, 'appears_corrected', $this->reporter);
        $laterStill = $this->inspectionBy($this->rig, $this->reporter, '2026-10-21 09:00:00');
        $this->observe($this->defect, $laterStill, 'confirmed_again', $this->reporter);

        $this->expectException(ValidationException::class);
        $this->service->verifyCorrection($this->defect->id, $this->admin);
    }

    public function test_classifying_impact_never_changes_the_unit_status_except_an_explicit_out_of_service(): void
    {
        $defect = $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'needs_repair']);
        $this->assertSame('needs_repair', $defect->operational_impact);
        $this->assertSame('In Service', $this->rig->fresh()->status);
        $this->assertSame(1, $this->eventsOf('impact_classified'));
    }

    public function test_out_of_service_requires_apparatus_update_permission_and_is_never_reversed_automatically(): void
    {
        try {
            $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'out_of_service']);
            $this->fail('Taking a unit out of service needs apparatus update authorization.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('In Service', $this->rig->fresh()->status);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertNotSame('out_of_service', $this->defect->fresh()->operational_impact);

        $this->allowApparatusUpdate($this->admin);
        $defect = $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'out_of_service']);

        $this->assertSame('out_of_service', $defect->operational_impact);
        $this->assertSame('Out of Service', $this->rig->fresh()->status);
        $this->assertTrue(ApparatusDefectEvent::query()->sole()->details['apparatus_status_changed']);

        $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'non_blocking']);
        $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());
        $this->assertSame('Out of Service', $this->rig->fresh()->status);
    }

    public function test_linking_a_ticket_is_explicit_idempotent_and_does_not_resolve_the_issue(): void
    {
        $this->allowApparatusUpdate($this->admin);

        $linked = $this->service->linkTicket($this->defect->id, $this->admin, []);
        $again = $this->service->linkTicket($this->defect->id, $this->admin, []);

        $this->assertNotNull($linked->service_ticket_id);
        $this->assertSame($linked->service_ticket_id, $again->service_ticket_id);
        $this->assertFalse($linked->resolved);
        $this->assertSame(1, $this->eventsOf('ticket_linked'));
        $this->assertSame(1, DB::table('apparatus_service_tickets')->count());
    }

    public function test_a_failed_notification_dispatch_does_not_roll_back_the_resolution(): void
    {
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class implements \Illuminate\Contracts\Notifications\Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new \RuntimeException('Notification transport is down.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('Notification transport is down.');
            }
        });

        $defect = $this->service->resolve($this->defect->id, $this->admin, $this->resolveData());

        $this->assertTrue($defect->resolved);
        $this->assertSame(1, $this->eventsOf('resolved'));
        $this->assertSame('failed', DB::table('inspection_notification_deliveries')->where('recipient_user_id', $this->reporter->id)->value('status'));
    }

    public function test_internal_notes_stay_off_messages_and_notifications(): void
    {
        $this->service->resolve($this->defect->id, $this->admin, $this->resolveData([
            'member_message' => 'The radio battery is back.',
            'internal_note' => 'SECRET vendor margin discussion.',
        ]));

        $this->assertStringNotContainsString('SECRET', ApparatusIssueMessage::query()->pluck('body')->implode(' '));
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, function (InspectionEventNotification $notification): bool {
            $payload = json_encode([$notification->title, $notification->body, $notification->toDatabase($this->reporter)]);

            return ! str_contains((string) $payload, 'SECRET') && ! str_contains((string) $payload, 'stock room');
        });
        $this->assertSame('SECRET vendor margin discussion.', ApparatusDefectEvent::query()->sole()->internal_note);
    }
}
