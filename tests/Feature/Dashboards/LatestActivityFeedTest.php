<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\Activity\OperationsActivityEvent;
use App\Services\Activity\OperationsActivityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LatestActivityFeedTest extends TestCase
{
    use LatestActivityFixtures;
    use RefreshDatabase;

    private OperationsActivityFeed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActivity();
        $this->feed = app(OperationsActivityFeed::class);
    }

    /** @return list<OperationsActivityEvent> */
    private function latest(User $user, string $filter = 'all'): array
    {
        $this->forgetFeeds();

        return $this->feed->latest($user, $filter, 40);
    }

    /** @return list<string> */
    private function domainsOf(User $user, string $filter = 'all'): array
    {
        return array_values(array_unique(array_map(static fn (OperationsActivityEvent $e): string => $e->domain, $this->latest($user, $filter))));
    }

    public function test_events_from_every_domain_appear_newest_first(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 30);
        $this->stationUpdate($this->stationRequest(), ['status' => 'pending'], 20);
        $this->personnelUpdate($this->personnelRequest(), 'submitted', [], 10);

        $events = $this->latest($this->superAdmin());

        $this->assertSame(['personnel', 'stations', 'apparatus'], array_map(static fn (OperationsActivityEvent $e): string => $e->domain, $events));
        $this->assertSame(['New request', 'New request', 'New issue'], $this->kindsOf($events));
        $this->assertTrue($events[0]->at->greaterThan($events[1]->at));
    }

    public function test_the_chip_maps_to_its_own_domains(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 30);
        $this->stationUpdate($this->stationRequest(), ['status' => 'pending'], 20);
        $this->personnelUpdate($this->personnelRequest(), 'submitted', [], 10);
        $admin = $this->superAdmin();

        $this->assertSame(['apparatus'], $this->domainsOf($admin, 'apparatus'));
        $this->assertSame(['stations'], $this->domainsOf($admin, 'stations'));
        $this->assertSame(['personnel'], $this->domainsOf($admin, 'personnel'));
        $this->assertCount(3, $this->latest($admin, 'all'));
        $this->assertSame($this->keysOf($this->latest($admin, 'all')), $this->keysOf($this->latest($admin, 'not-a-chip')));
    }

    public function test_a_domain_the_user_may_not_see_contributes_nothing(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 30);
        $this->stationUpdate($this->stationRequest(), ['status' => 'pending'], 20);
        $this->personnelUpdate($this->personnelRequest(), 'submitted', [], 10);

        $stationsOnly = $this->adminCan(['admin.stations.view']);
        $this->assertSame(['stations'], $this->domainsOf($stationsOnly));
        $this->assertSame(['personnel'], $this->domainsOf($this->adminCan(['admin.personnel.view'])));
        $this->assertSame(['apparatus'], $this->domainsOf($this->fleetViewer()));

        $nobody = $this->adminCan([]);
        $this->assertSame([], $this->latest($nobody));
        $this->assertFalse($this->feed->canSeeAny($nobody));
        $this->assertTrue($this->feed->canSeeAny($stationsOnly));
    }

    public function test_station_events_are_hidden_without_the_station_permission(): void
    {
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending', 'internal_note' => 'Call the plumber'], 5);
        $withoutStations = $this->adminCan(['admin.personnel.view']);

        $this->assertSame([], $this->latest($withoutStations, 'stations'));
        $this->assertSame([], $this->latest($withoutStations, 'all'));
    }

    public function test_an_automated_receipt_is_labelled_and_never_needs_action(): void
    {
        $request = $this->stationRequest(['title' => 'Kitchen sink leak']);
        $this->stationUpdate($request, ['status' => 'pending'], 10);
        $this->stationUpdate($request, ['status' => 'pending', 'public_note' => 'We received your request.', 'automation_intake_id' => 77], 9);

        $events = $this->latest($this->superAdmin(), 'stations');

        $this->assertSame(['Support Services reply', 'New request'], $this->kindsOf($events));
        $receipt = $events[0];
        $this->assertTrue($receipt->automated);
        $this->assertFalse($receipt->actionRequired);
        $this->assertTrue($receipt->message);
        $this->assertSame('Support Services (automated)', $receipt->actorLabel);
        $this->assertTrue($events[1]->actionRequired, 'the request itself is still waiting for a person');
    }

    public function test_a_new_station_request_needs_action_only_while_it_is_pending(): void
    {
        $request = $this->stationRequest();
        $this->stationUpdate($request, ['status' => 'pending'], 10);

        $this->assertTrue($this->latest($this->superAdmin(), 'stations')[0]->actionRequired);

        StationRequest::query()->whereKey($request->id)->update(['status' => 'acknowledged']);
        $this->assertFalse($this->latest($this->superAdmin(), 'stations')[0]->actionRequired);
    }

    public function test_a_member_resolved_station_request_has_its_own_label(): void
    {
        $request = $this->stationRequest(['status' => 'completed']);
        $this->stationUpdate($request, ['status' => 'completed', 'changed_by_user_id' => $this->activityReporter->id, 'metadata' => ['event' => 'member_resolved']], 5);

        $events = $this->latest($this->superAdmin(), 'stations');

        $this->assertSame('Request resolved by member', $events[0]->kind);
        $this->assertSame('Riley Reporter', $events[0]->actorLabel);
        $this->assertFalse($events[0]->actionRequired);
    }

    public function test_a_member_message_needs_action_until_a_reply_arrives(): void
    {
        $defect = $this->defectIn();
        $this->issueMessage($defect, 'member', 'Is the battery still on order?', minutesAgo: 10);
        $admin = $this->reviewerAdmin();

        $events = $this->latest($admin, 'apparatus');
        $this->assertSame(['Member message'], $this->kindsOf($events));
        $this->assertTrue($events[0]->actionRequired);
        $this->assertTrue($events[0]->message);

        $this->issueMessage($defect, 'logistics', 'Ordered, due Friday.', minutesAgo: 2, author: $admin);
        $events = $this->latest($admin, 'apparatus');
        $this->assertSame(['Staff reply', 'Member message'], $this->kindsOf($events));
        $this->assertFalse($events[1]->actionRequired);
        $this->assertFalse($events[0]->actionRequired);
    }

    public function test_a_staff_reply_written_with_an_event_is_listed_once(): void
    {
        $defect = $this->defectIn('Fog nozzle');
        $admin = $this->reviewerAdmin();
        // DefectCorrectiveActionService stores the member message on the event and again as a member-visible message.
        $this->defectEvent($defect, 'acknowledged', ['actor_user_id' => $admin->id, 'member_message' => 'We have it on order'], 5);
        $this->issueMessage($defect, 'logistics', 'We have it on order', minutesAgo: 5, author: $admin);

        $events = $this->latest($admin, 'apparatus');

        $this->assertSame(['Acknowledged'], $this->kindsOf($events));
        $this->assertTrue($events[0]->message);
        $this->assertStringContainsString('We have it on order', $events[0]->summary);
    }

    public function test_a_message_on_an_inspection_with_no_issue_still_names_the_unit_and_links_to_the_checkout(): void
    {
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $this->activityInspection->id, 'author_user_id' => $this->activityReporter->id,
            'author_context' => 'member', 'visibility' => 'member', 'body' => 'Was the oil level checked?',
            'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
        ]);

        $events = $this->latest($this->reviewerAdmin(), 'apparatus');

        $this->assertSame(['Member message'], $this->kindsOf($events));
        $this->assertSame('R2', $events[0]->subject);
        $this->assertSame('Checkout message', $events[0]->statusLabel);
        $this->assertSame(InspectionWorkspace::url((int) $this->activityInspection->id), $events[0]->url);
        $this->assertTrue($events[0]->actionRequired);
    }

    public function test_an_assistant_reply_is_automated_and_answers_the_thread(): void
    {
        $defect = $this->defectIn();
        $this->issueMessage($defect, 'member', 'Still missing.', minutesAgo: 10);
        $this->issueMessage($defect, 'assistant', 'Received. Support Services will follow up.', minutesAgo: 9);

        $events = $this->latest($this->reviewerAdmin(), 'apparatus');

        $this->assertSame(['Support Services reply', 'Member message'], $this->kindsOf($events));
        $this->assertTrue($events[0]->automated);
        $this->assertSame('Support Services (automated)', $events[0]->actorLabel);
        $this->assertFalse($events[0]->actionRequired);
        $this->assertFalse($events[1]->actionRequired);
    }

    public function test_a_new_issue_and_a_member_correction_need_action_until_handled(): void
    {
        $defect = $this->defectIn();
        $this->defectEvent($defect, 'reported', [], 20);
        $this->defectEvent($defect, 'correction_reported', ['details' => ['note' => 'Found it in the cab']], 10);
        $admin = $this->reviewerAdmin();

        $events = $this->latest($admin, 'apparatus');
        $this->assertSame(['Needs verification', 'New issue'], $this->kindsOf($events));
        $this->assertTrue($events[0]->actionRequired);
        $this->assertTrue($events[1]->actionRequired);

        $defect->forceFill(['action_status' => 'acknowledged'])->save();
        $events = $this->latest($admin, 'apparatus');
        $this->assertTrue($events[0]->actionRequired, 'a correction still waits for verification');
        $this->assertFalse($events[1]->actionRequired, 'an acknowledged issue is no longer new');

        $defect->forceFill(['action_status' => 'resolved'])->save();
        $events = $this->latest($admin, 'apparatus');
        $this->assertFalse($events[0]->actionRequired);
        $this->assertSame('Resolved', $events[0]->statusLabel);
    }

    public function test_internal_notes_are_tagged_for_staff_and_never_shown_to_view_only_accounts(): void
    {
        $defect = $this->defectIn();
        $this->defectEvent($defect, 'action_updated', ['internal_note' => 'Vendor says backordered 3 weeks'], 8);
        $this->issueMessage($defect, 'logistics', 'Staff only: check the other rig first', 'internal', 6, $this->reviewerAdmin());

        $reviewer = $this->latest($this->reviewerAdmin(), 'apparatus');
        $this->assertSame(['Internal note', 'Status update'], $this->kindsOf($reviewer));
        $this->assertTrue($reviewer[0]->internal);
        $this->assertStringContainsString('check the other rig first', $reviewer[0]->summary);
        $this->assertTrue($reviewer[1]->internal);
        $this->assertStringContainsString('backordered 3 weeks', $reviewer[1]->summary);

        $viewer = $this->latest($this->fleetViewer(), 'apparatus');
        $this->assertSame(['Status update'], $this->kindsOf($viewer), 'the internal message is not listed at all');
        $this->assertFalse($viewer[0]->internal);
        $this->assertStringNotContainsString('backordered', $viewer[0]->summary);
        $this->assertStringNotContainsString('other rig', implode(' ', array_map(static fn (OperationsActivityEvent $e): string => $e->summary, $viewer)));
    }

    public function test_captain_5_owned_medical_issues_stay_off_the_admin_feed(): void
    {
        $captain5 = $this->defectIn('Stretcher battery', 'captain5');
        $logistics = $this->defectIn('Fog nozzle', 'logistics');
        $this->defectEvent($captain5, 'reported', [], 10);
        $this->defectEvent($logistics, 'reported', [], 9);
        $this->issueMessage($captain5, 'member', 'Battery is dead again', minutesAgo: 8);

        $byKey = [];
        foreach ($this->latest($this->reviewerAdmin(), 'apparatus') as $event) {
            $byKey[$event->kind.'|'.$event->summary] = $event;
        }

        $nozzle = $byKey['New issue|Missing: Fog nozzle'];
        $this->assertNull($nozzle->ownerTag);
        $this->assertTrue($nozzle->actionRequired);

        // Owner rule 2026-11-05: medical submissions stay with Captain 5, never on the admin dashboard.
        $this->assertArrayNotHasKey('New issue|Missing: Stretcher battery', $byKey);
        $this->assertArrayNotHasKey('Member message|Stretcher battery: Battery is dead again', $byKey);
    }

    public function test_an_ownership_move_is_listed_with_where_it_went(): void
    {
        // Sent to Captain 5, then brought back to Logistics: Logistics owns it now, so both moves show.
        $defect = $this->defectIn('Stretcher battery', 'logistics');
        $this->defectEvent($defect, 'owner_transferred', ['actor_user_id' => null, 'details' => ['from' => 'logistics', 'to' => 'captain5', 'basis' => 'medical_item']], 4);
        $this->defectEvent($defect, 'owner_transferred', ['details' => ['from' => 'captain5', 'to' => 'logistics', 'basis' => 'manual']], 3);

        $events = $this->latest($this->reviewerAdmin(), 'apparatus');

        $this->assertSame(['Moved to Logistics', 'Moved to Captain 5'], $this->kindsOf($events));
        $this->assertSame('System', $events[1]->actorLabel);
        $this->assertStringContainsString('From Logistics to Captain 5', $events[1]->summary);
    }

    public function test_page_only_noise_and_old_events_are_left_out_of_the_card(): void
    {
        $defect = $this->defectIn();
        $this->defectEvent($defect, 'observed_again', [], 5);
        $this->defectEvent($defect, 'cleared_from_captain5', [], 5);
        $this->defectEvent($defect, 'impact_classified', [], 5);
        $this->defectEvent($defect, 'reported', [], 60 * 24 * 20);
        $this->defectEvent($defect, 'resolved', ['details' => ['resolution_notes' => 'Replaced']], 5);

        $this->assertSame(['Resolved'], $this->kindsOf($this->latest($this->reviewerAdmin(), 'apparatus')));
        $this->assertCount(2, $this->feed->history($this->reviewerAdmin()), 'the 30-day history still has the 20-day-old report');
    }

    public function test_summaries_stay_inside_140_characters(): void
    {
        $this->issueMessage($this->defectIn(), 'member', str_repeat('The strap is frayed and the buckle sticks. ', 20));

        $events = $this->latest($this->reviewerAdmin(), 'apparatus');

        $this->assertLessThanOrEqual(140, mb_strlen($events[0]->summary));
        $this->assertStringEndsWith('…', $events[0]->summary);
    }

    public function test_support_services_conversations_need_action_until_a_person_answers(): void
    {
        $responder = $this->logisticsResponder();
        $message = $this->memberSends($this->messagingMember('Ann Jones'), 'My boots arrived damaged');

        $events = $this->latest($responder, 'personnel');
        $this->assertSame(['Member message'], $this->kindsOf($events));
        $this->assertTrue($events[0]->actionRequired);
        $this->assertSame('Ann Jones', $events[0]->subject);

        $this->assistantReply((int) $message->thread_id);
        $events = $this->latest($responder, 'personnel');
        $this->assertSame(['Support Services reply', 'Member message'], $this->kindsOf($events));
        $this->assertTrue($events[0]->automated);
        $this->assertFalse($events[0]->actionRequired);
        $this->assertTrue($events[1]->actionRequired, 'the assistant never counts as a team reply');

        $this->logisticsReplies($responder, $message);
        $events = $this->latest($responder, 'personnel');
        $this->assertFalse(collect($events)->contains(static fn (OperationsActivityEvent $e): bool => $e->actionRequired));
        $this->assertContains('Staff reply', $this->kindsOf($events));

        $this->assertSame([], $this->latest($this->unrelatedAdmin(), 'personnel'), 'no inbox access, no conversations');
    }

    public function test_personnel_requests_follow_their_own_status_and_member_responses(): void
    {
        $request = $this->personnelRequest('UR-2002');
        $this->personnelUpdate($request, 'submitted', [], 30);
        $this->personnelUpdate($request, 'employee_responded', ['employee_visible_note' => 'Size is large, not medium'], 10);

        $events = $this->latest($this->adminCan(['admin.personnel.view']), 'personnel');

        $this->assertSame(['Member message', 'New request'], $this->kindsOf($events));
        $this->assertSame('UR-2002', $events[0]->subject);
        $this->assertTrue($events[0]->actionRequired);
        $this->assertTrue($events[1]->actionRequired);
        $this->assertStringContainsString('Size is large', $events[0]->summary);
        // The link opens the request: the resource's route key is public_id, so a numeric id would 404.
        $this->assertStringEndsWith('/personnel-requests/'.$request->public_id, (string) $events[0]->url);
        $this->assertStringNotContainsString('/personnel-requests/'.$request->getKey(), (string) $events[0]->url.'#');

        $admin = $this->superAdmin();
        $this->personnelUpdate($request, 'status_changed', ['status' => 'completed', 'changed_by_admin_id' => $admin->id], 2);
        $request->forceFill(['status' => 'completed'])->save();
        $events = $this->latest($admin, 'personnel');
        $this->assertSame('Completed', $events[0]->kind);
        $this->assertFalse($events[1]->actionRequired);
        $this->assertFalse($events[2]->actionRequired);
    }

    public function test_equipment_change_requests_are_part_of_the_apparatus_chip_for_reviewers_only(): void
    {
        $correction = ApparatusInventoryCorrection::query()->create([
            'client_request_id' => (string) str()->uuid(), 'payload_hash' => str_repeat('a', 64),
            'apparatus_id' => $this->activityRig->id, 'reporter_user_id' => $this->activityReporter->id,
            'reason' => 'missing_from_list', 'target_snapshot' => ['name' => 'Rope bag', 'compartment' => 'Left side'],
            'checklist_type' => 'rescue', 'checklist_version' => 'v1', 'status' => ApparatusInventoryCorrection::STATUS_SUBMITTED,
        ]);
        foreach ([['submitted', 'member', 'Add the rope bag to the list', 'member', 20], ['internal_note', 'logistics', 'Check the other rescue first', 'internal', 5]] as [$type, $context, $body, $visibility, $minutes]) {
            ApparatusInventoryCorrectionEvent::query()->create([
                'apparatus_inventory_correction_id' => $correction->id, 'actor_user_id' => $this->activityReporter->id,
                'event_type' => $type, 'visibility' => $visibility, 'author_context' => $context, 'body' => $body,
                'created_at' => now()->subMinutes($minutes), 'updated_at' => now()->subMinutes($minutes),
            ]);
        }

        $events = $this->latest($this->reviewerAdmin(), 'apparatus');

        $this->assertSame(['Internal note', 'Equipment change requested'], $this->kindsOf($events));
        $this->assertTrue($events[0]->internal);
        $this->assertFalse($events[0]->actionRequired);
        $this->assertTrue($events[1]->actionRequired);
        $this->assertSame('R2', $events[1]->subject);
        $this->assertSame('Pending approval', $events[1]->statusLabel);
        $this->assertSame([], $this->latest($this->fleetViewer(), 'apparatus'), 'only reviewers see equipment change requests');
    }

    public function test_history_searches_and_filters_the_thirty_day_window(): void
    {
        $nozzle = $this->defectIn('Fog nozzle');
        $this->defectEvent($nozzle, 'reported', [], 60 * 24 * 25);
        $this->stationUpdate($this->stationRequest(['title' => 'Kitchen sink leak']), ['status' => 'pending'], 10);
        $this->defectEvent($nozzle, 'reported', [], 60 * 24 * 40);
        $admin = $this->superAdmin();

        $this->assertCount(2, $this->feed->history($admin));
        $this->assertCount(0, $this->latest($admin, 'apparatus'), 'older than 14 days is out of the card');
        $this->assertCount(1, $this->feed->history($admin, 'all', 'sink'));
        $this->assertCount(1, $this->feed->history($admin, 'all', 'FOG nozzle'));
        $this->assertCount(0, $this->feed->history($admin, 'all', 'no such words'));
        $this->assertCount(2, $this->feed->history($admin, 'all', '', 'needs_action'));
        $this->assertCount(0, $this->feed->history($admin, 'all', '', 'messages'));
    }

    public function test_the_card_list_is_cached_for_a_few_seconds_per_user_and_chip(): void
    {
        $this->defectEvent($this->defectIn(), 'reported', [], 5);
        $admin = $this->reviewerAdmin();
        $this->forgetFeeds();
        $this->feed->latest($admin, 'all');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->feed->latest($admin, 'all');
        $cached = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->feed->latest($admin, 'all', 12, fresh: true);
        $fresh = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $cached);
        $this->assertGreaterThan(0, $fresh);
    }
}
