<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What any member may read about the issues on a physical apparatus (docs/apparatus-issue-history.md section 2).
 */
final class ApparatusIssueHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $member;

    private Apparatus $truck;

    private int $tick = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->member = $this->actingAsCanonicalFixture('E01-HISTORY', 'History Member');
        $this->truck = $this->apparatus('E2', 'TEST-102', 'engine');
    }

    // ------------------------------------------------------------------ access

    public function test_a_signed_out_caller_gets_401_from_every_endpoint(): void
    {
        [$defect, $observation, $concern] = $this->photoFixtures();
        $this->logoutCanonicalSession();

        foreach ($this->urls($defect, $observation, $concern) as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_an_account_without_an_employee_profile_is_refused_by_every_endpoint(): void
    {
        [$defect, $observation, $concern] = $this->photoFixtures();
        $this->actingAsCanonicalUser(User::factory()->create(['account_status' => AccountStatus::Active]));

        foreach ($this->urls($defect, $observation, $concern) as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_every_lifecycle_state_is_listed_with_its_label_and_how_it_ended(): void
    {
        $staff = User::factory()->create(['name' => 'Staff Member']);
        $made = [];
        foreach (DefectActionStatus::all() as $status) {
            $defect = $this->defect(['compartment' => 'Deck', 'item' => "Item {$status}", 'action_status' => $status]);
            $this->event($defect, 'reported', ['to_status' => 'reported', 'actor_user_id' => $this->member->id]);
            if ($status !== 'reported') {
                $type = ['action_pending' => 'action_updated'][$status] ?? $status;
                $this->event($defect, $type, ['from_status' => 'reported', 'to_status' => $status, 'actor_user_id' => $staff->id,
                    'details' => $status === 'dismissed' ? ['reason' => 'Entered by mistake'] : null]);
            }
            $made[$status] = $defect;
        }

        $list = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk();
        $closed = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed")->assertOk();

        $open = collect($list->json('open'))->keyBy('action_status');
        $this->assertSame(['reported', 'acknowledged', 'action_pending', 'replacement_available', 'repair_scheduled'], $open->keys()->all());
        $ended = collect($closed->json('data'))->keyBy('action_status');
        $this->assertEqualsCanonicalizing(['resolved', 'dismissed'], $ended->keys()->all());
        foreach ([...$open->all(), ...$ended->all()] as $status => $issue) {
            $this->assertSame($made[$status]->id, $issue['id']);
            $this->assertSame(DefectActionStatus::label($status), $issue['action_label'], $status);
            $this->assertSame(DefectActionStatus::closureKind($status), $issue['closure_kind'], $status);
        }
        $this->assertNull($open['reported']['latest_action'], 'Nothing has been done about a new report yet');
        $this->assertSame('Acknowledged', $open['acknowledged']['latest_action']['label']);
        $this->assertSame('Staff Member', $open['acknowledged']['latest_action']['actor_name']);
        $this->assertSame('Repair scheduled', $open['repair_scheduled']['latest_action']['label']);
        $this->assertSame('Resolved', $ended['resolved']['latest_action']['label']);
        $this->assertSame('Closed — no action required', $ended['dismissed']['action_label']);
        $this->assertSame('closed_no_action', $ended['dismissed']['closure_kind']);
        $this->assertSame(2, $list->json('closed_count'));
    }

    public function test_a_reopened_issue_and_a_recurrence_tell_their_story(): void
    {
        $staff = User::factory()->create(['name' => 'Staff Member']);
        $first = $this->defect(['item' => 'Hinge', 'action_status' => 'reported']);
        $this->event($first, 'reported', ['to_status' => 'reported']);
        $this->event($first, 'resolved', ['from_status' => 'reported', 'to_status' => 'resolved', 'actor_user_id' => $staff->id, 'details' => ['resolution_notes' => 'New hinge fitted']]);
        $this->event($first, 'reopened', ['from_status' => 'resolved', 'to_status' => 'reported', 'actor_user_id' => $staff->id, 'details' => ['reason' => 'It cracked again']]);
        $later = $this->defect(['item' => 'Latch', 'recurrence_of_defect_id' => $first->id]);

        $timeline = $this->getJson("/api/public/apparatus-issues/{$first->id}/history")->assertOk();

        $this->assertSame(['reported', 'resolved', 'reopened'], array_column($timeline->json('timeline'), 'type'));
        $this->assertSame(['Notes: New hinge fitted'], $timeline->json('timeline.1.lines'));
        $this->assertSame(['Reason: It cracked again'], $timeline->json('timeline.2.lines'));
        $this->assertSame('Reopened', $timeline->json('timeline.2.label'));
        $timeline->assertJsonPath('issue.action_status', 'reported')->assertJsonPath('issue.closure_kind', null);
        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()
            ->assertJsonPath('open.1.id', $later->id)->assertJsonPath('open.1.recurrence_of_defect_id', $first->id);
    }

    public function test_names_ordering_and_the_latest_action_follow_the_record(): void
    {
        $reporter = User::factory()->create(['name' => 'Account Reporter', 'display_name' => 'Reporter Display']);
        $logistics = User::factory()->create(['name' => 'Logistics Person', 'display_name' => '']);
        $captain = User::factory()->create(['name' => 'Captain Name']);
        $defect = $this->defect(['first_reported_by_user_id' => $reporter->id, 'first_reported_at' => CarbonImmutable::parse('2026-10-31 14:00:00'), 'notes' => 'Original words']);
        $at = static fn (string $time): array => ['created_at' => CarbonImmutable::parse("2026-10-31 {$time}"), 'updated_at' => CarbonImmutable::parse("2026-10-31 {$time}")];
        // Inserted out of order on purpose: the record is read oldest first by its time.
        $acknowledged = $this->event($defect, 'acknowledged', ['from_status' => 'reported', 'to_status' => 'acknowledged', 'actor_user_id' => $logistics->id, ...$at('14:30:00')]);
        $reported = $this->event($defect, 'reported', ['to_status' => 'reported', 'actor_user_id' => $reporter->id, 'details' => ['notes' => 'Original words'], ...$at('14:00:00')]);
        $ordered = $this->event($defect, 'action_updated', ['from_status' => 'acknowledged', 'to_status' => 'acknowledged', 'actor_user_id' => $captain->id,
            'member_message' => 'Part ordered, due Friday', 'details' => ['actor_context' => ['role' => 'captain5', 'label' => 'Captain 5 (acting)']], ...$at('15:00:00')]);
        $nobody = $this->event($defect, 'observed_again', ['actor_user_id' => null, 'details' => ['notes' => 'Seen again'], ...$at('16:00:00')]);
        $message = $this->message($defect, $logistics, 'We will fix it Monday', ApparatusIssueMessage::CONTEXT_LOGISTICS, $at('14:45:00'));

        $timeline = $this->getJson("/api/public/apparatus-issues/{$defect->id}/history")->assertOk();

        $entries = $timeline->json('timeline');
        $this->assertSame(
            ["event-{$reported->id}", "event-{$acknowledged->id}", "message-{$message->id}", "event-{$ordered->id}", "event-{$nobody->id}"],
            array_column($entries, 'id'),
        );
        $this->assertSame(['Reporter Display', 'Logistics Person', 'Logistics Person', 'Captain 5 (acting) · Captain Name', null], array_column($entries, 'actor_name'));
        $this->assertSame(['event', 'event', 'message', 'event', 'event'], array_column($entries, 'kind'));
        $this->assertSame(['reported', 'acknowledged', 'update', 'action_updated', 'observed_again'], array_column($entries, 'type'));
        $this->assertSame('Original words', $entries[0]['text']);
        $this->assertSame('We will fix it Monday', $entries[2]['text']);
        $this->assertSame('Part ordered, due Friday', $entries[3]['text']);
        $this->assertSame('Seen again', $entries[4]['text']);
        $this->assertSame('Issue reported', $entries[0]['label']);
        $this->assertSame('Update from Logistics', $entries[2]['label']);
        $this->assertTrue(CarbonImmutable::parse($entries[0]['at'])->equalTo(CarbonImmutable::parse('2026-10-31 14:00:00')));
        $this->assertStringEndsWith('-04:00', $entries[0]['at']);
        $timeline->assertJsonPath('issue.reporter_name', 'Reporter Display')
            ->assertJsonPath('issue.description', 'Original words')
            ->assertJsonPath('issue.history_count', 5);

        // The newest thing the department did, not the newest sighting by a member.
        $latest = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->json('open.0.latest_action');
        $this->assertSame('Corrective action updated', $latest['label']);
        $this->assertSame('Captain 5 (acting) · Captain Name', $latest['actor_name']);
        $this->assertSame('Part ordered, due Friday', $latest['text']);
        $this->assertTrue(CarbonImmutable::parse($latest['at'])->equalTo(CarbonImmutable::parse('2026-10-31 15:00:00')));
    }

    public function test_the_account_name_is_used_when_there_is_no_display_name(): void
    {
        $plain = User::factory()->create(['name' => 'Plain Name', 'display_name' => null]);
        $defect = $this->defect(['first_reported_by_user_id' => $plain->id]);
        $this->defect(['item' => 'Unreported', 'first_reported_by_user_id' => null]);

        $open = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()->json('open');

        $this->assertSame($defect->id, $open[0]['id']);
        $this->assertSame('Plain Name', $open[0]['reporter_name']);
        $this->assertNull($open[1]['reporter_name']);
    }

    // ------------------------------------------------------------------ what never leaves the server

    public function test_nothing_internal_appears_in_any_response(): void
    {
        $staff = User::factory()->create(['name' => 'Staff Member']);
        $other = $this->apparatus('E9', 'TEST-109', 'engine');
        $defect = $this->defect([
            'resolution_notes' => 'SECRET-RESOLUTION-NOTES',
            'action_details' => ['vendor_reference' => 'SECRET-ACTION-DETAILS'],
            'photo_path' => 'defects/secret-defect-photo.png',
            'notes' => 'Visible original report',
        ]);
        $closed = $this->defect(['item' => 'Closed one', 'action_status' => 'dismissed', 'resolution_notes' => 'SECRET-CLOSED-NOTES']);
        $this->event($defect, 'reported', ['to_status' => 'reported', 'details' => ['notes' => 'Visible original report', 'secret' => 'SECRET-DETAILS-VALUE'], 'internal_note' => 'SECRET-INTERNAL-NOTE']);
        $this->event($defect, 'action_updated', ['from_status' => 'reported', 'to_status' => 'reported', 'actor_user_id' => $staff->id,
            'member_message' => 'Visible member message', 'internal_note' => 'SECRET-INTERNAL-NOTE-2', 'details' => ['vendor_reference' => 'SECRET-VENDOR', 'expected_delivery' => 'SECRET-DELIVERY']]);
        foreach (ApparatusDefectEvent::PAGE_ONLY_EVENTS as $pageOnly) {
            $this->event($defect, $pageOnly, ['actor_user_id' => $staff->id, 'member_message' => 'SECRET-PAGE-ONLY-MESSAGE', 'details' => ['note' => 'SECRET-PAGE-ONLY-DETAILS']]);
        }
        $this->event($closed, 'dismissed', ['from_status' => 'reported', 'to_status' => 'dismissed', 'internal_note' => 'SECRET-CLOSED-INTERNAL', 'details' => ['reason' => 'Visible closing reason', 'secret' => 'SECRET-CLOSED-DETAILS']]);
        $this->message($defect, $staff, 'Visible staff message', ApparatusIssueMessage::CONTEXT_LOGISTICS);
        $this->message($defect, $staff, 'SECRET-INTERNAL-MESSAGE', ApparatusIssueMessage::CONTEXT_LOGISTICS, ['visibility' => ApparatusIssueMessage::VISIBILITY_INTERNAL]);
        $this->message($defect, $this->member, 'SECRET-MEMBER-REPLY', ApparatusIssueMessage::CONTEXT_MEMBER);
        $this->message($defect, $this->member, 'SECRET-MEMBER-INTERNAL', ApparatusIssueMessage::CONTEXT_MEMBER, ['visibility' => ApparatusIssueMessage::VISIBILITY_INTERNAL]);
        $this->defect(['apparatus' => $other, 'item' => 'Another truck', 'notes' => 'SECRET-OTHER-TRUCK']);
        $inspection = $this->inspection();
        ApparatusDefectObservation::query()->create(['apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id, 'observation' => 'confirmed_again',
            'notes' => 'Visible sighting', 'photo_path' => 'defects/secret-observation-photo.png']);
        $this->event($defect, 'observed_again', ['apparatus_inspection_id' => $inspection->id]);
        ApparatusInspectionConcern::query()->create(['apparatus_id' => $this->truck->id, 'apparatus_inspection_id' => $inspection->id, 'client_id' => '81818181-8181-4181-8181-818181818181',
            'kind' => 'note', 'description' => 'Visible note', 'photo_path' => 'defects/secret-note-photo.png', 'reporter_name' => 'Note Writer']);

        $bodies = [
            $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()->getContent(),
            $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed")->assertOk()->getContent(),
            $this->getJson("/api/public/apparatus-issues/{$defect->id}/history")->assertOk()->getContent(),
            $this->getJson("/api/public/apparatus-issues/{$closed->id}/history")->assertOk()->getContent(),
        ];

        $all = implode("\n", $bodies);
        $this->assertStringNotContainsString('SECRET', $all);
        $this->assertStringNotContainsString('secret', $all, 'No storage path leaves the server');
        $this->assertStringNotContainsString('internal_note', $all);
        $this->assertStringNotContainsString('"details"', $all);
        $this->assertStringNotContainsString('action_details', $all);
        $this->assertStringNotContainsString('resolution_notes', $all);
        $this->assertStringNotContainsString('photo_path', $all);
        foreach (['Visible original report', 'Visible member message', 'Visible staff message', 'Visible sighting', 'Visible note', 'Visible closing reason'] as $expected) {
            $this->assertStringContainsString($expected, $all);
        }
        $history = json_decode($bodies[2], true, 512, JSON_THROW_ON_ERROR);
        $types = array_column($history['timeline'], 'type');
        $this->assertNotContains('cleared_from_captain5', $types);
        $this->assertNotContains('returned_to_captain5', $types);
        $this->assertSame(4, $history['issue']['history_count'], 'Three events and one staff message: page-only events and replies are not history here');
        $this->assertCount(4, $history['timeline']);
    }

    // ------------------------------------------------------------------ placing an issue on the checklist

    public function test_an_issue_is_placed_on_the_current_checklist_and_an_unmatched_one_is_still_listed(): void
    {
        $renamed = $this->defect(['compartment' => 'Front Cab', 'item' => 'Fire Violation', 'compartment_key' => null, 'item_key' => null]);
        $unmatched = $this->defect(['compartment' => 'Old Locker', 'item' => 'Mystery gizmo', 'compartment_key' => null, 'item_key' => null]);
        $keyed = $this->defect(['compartment' => 'Retired name', 'item' => 'Retired item', 'compartment_key' => 'front_cab', 'item_key' => 'front_cab-item-3']);

        $open = collect($this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()->json('open'))->keyBy('id');

        $this->assertSame(['Front Cab', 'Fire Violations', 'front_cab', 'front_cab-item-14'],
            [$open[$renamed->id]['compartment'], $open[$renamed->id]['item'], $open[$renamed->id]['compartment_id'], $open[$renamed->id]['item_id']]);
        $this->assertSame(['Old Locker', 'Mystery gizmo', null, null],
            [$open[$unmatched->id]['compartment'], $open[$unmatched->id]['item'], $open[$unmatched->id]['compartment_id'], $open[$unmatched->id]['item_id']]);
        $this->assertSame(['Retired name', 'Retired item', 'front_cab', 'front_cab-item-3'],
            [$open[$keyed->id]['compartment'], $open[$keyed->id]['item'], $open[$keyed->id]['compartment_id'], $open[$keyed->id]['item_id']]);
    }

    public function test_an_unusable_checklist_falls_back_to_the_stored_names_and_keys(): void
    {
        $nothing = Apparatus::query()->create(['name' => 'Mystery', 'unit_id' => 'X1', 'designation' => 'X1', 'type' => 'Rental', 'vehicle_number' => 'TEST-X1', 'status' => 'In Service']);
        $defect = $this->defect(['apparatus' => $nothing, 'compartment' => 'Back', 'item' => 'Box', 'compartment_key' => 'back', 'item_key' => 'back-1']);

        $this->getJson("/api/public/apparatuses/{$nothing->id}/findings")->assertOk()
            ->assertJsonPath('open.0.id', $defect->id)
            ->assertJsonPath('open.0.compartment', 'Back')
            ->assertJsonPath('open.0.item_id', 'back-1');
    }

    public function test_issues_stay_on_the_physical_apparatus_after_a_changeout(): void
    {
        $other = $this->apparatus('E3', 'TEST-103', 'engine');
        $defect = $this->defect(['item' => 'Cracked mirror']);
        // The units swap trucks: each row keeps its own vehicle, and the unit names move. History belongs to the vehicle.
        $this->truck->update(['unit_id' => 'TMP']);
        $other->update(['designation' => 'E2', 'unit_id' => 'E2']);
        $this->truck->update(['designation' => 'E3', 'unit_id' => 'E3']);

        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()
            ->assertJsonPath('open.0.id', $defect->id);
        $this->getJson("/api/public/apparatuses/{$other->id}/findings")->assertOk()
            ->assertJsonPath('open', []);
    }

    // ------------------------------------------------------------------ closed issues

    public function test_closed_issues_page_newest_first_with_a_cursor(): void
    {
        $ids = [];
        for ($i = 1; $i <= 25; $i++) {
            $ids[] = $this->defect(['item' => "Closed {$i}", 'action_status' => $i % 2 === 0 ? 'dismissed' : 'resolved'])->id;
        }
        $this->defect(['item' => 'Still open']);

        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()->assertJsonPath('closed_count', 25);
        $first = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed")->assertOk();
        $this->assertCount(20, $first->json('data'));
        $this->assertSame(array_reverse(array_slice($ids, 5)), array_column($first->json('data'), 'id'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed?cursor=".urlencode($first->json('next_cursor')))->assertOk();
        $this->assertSame(array_reverse(array_slice($ids, 0, 5)), array_column($second->json('data'), 'id'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertNotContains('Still open', array_column($first->json('data'), 'item'));
        $this->assertEqualsCanonicalizing(['resolved', 'closed_no_action'], array_values(array_unique(array_column($first->json('data'), 'closure_kind'))));
    }

    public function test_a_hand_made_cursor_is_refused_without_an_error_page(): void
    {
        $this->defect(['action_status' => 'resolved']);

        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed?cursor=".rtrim(strtr(base64_encode('{"x":1}'), '+/', '-_'), '='))
            ->assertUnprocessable();
        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed?cursor=".str_repeat('a', 600))->assertUnprocessable();
        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings/closed?cursor=garbage")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_description_is_the_original_report_even_after_staff_edit_the_notes(): void
    {
        $reported = $this->defect(['notes' => 'Logistics: vendor quote pending, do not tell crew']);
        $this->event($reported, 'reported', ['details' => ['reported_status' => 'Damaged', 'notes' => 'Handle is cracked']]);
        $silent = $this->defect(['item' => 'Reported with no words', 'notes' => 'Staff added this later']);
        $this->event($silent, 'reported', ['details' => ['reported_status' => 'Missing']]);
        $older = $this->defect(['item' => 'From before the event history', 'notes' => 'Older report text']);

        $response = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk();

        $byId = collect($response->json('open'))->keyBy('id');
        $this->assertSame('Handle is cracked', $byId[$reported->id]['description']);
        $this->assertNull($byId[$silent->id]['description']);
        $this->assertSame('Older report text', $byId[$older->id]['description']);
        $this->assertStringNotContainsString('vendor quote', $response->getContent());
        $this->assertStringNotContainsString('Staff added this later', $response->getContent());
    }

    public function test_an_unknown_apparatus_or_issue_is_a_404(): void
    {
        $this->getJson('/api/public/apparatuses/999999/findings')->assertNotFound();
        $this->getJson('/api/public/apparatus-issues/999999/history')->assertNotFound();
        $this->getJson('/api/public/apparatuses/abc/findings')->assertNotFound();
    }

    // ------------------------------------------------------------------ notes

    public function test_notes_are_the_latest_twenty_newest_first_and_leave_out_excluded_tests(): void
    {
        $inspection = $this->inspection();
        for ($i = 1; $i <= 22; $i++) {
            $this->concern($inspection, ['description' => "Note {$i}"]);
        }
        $this->concern($inspection, ['description' => 'An issue', 'kind' => 'issue']);
        $excluded = $this->inspection();
        $excluded->forceFill(['test_excluded_at' => now()])->save();
        $this->concern($excluded, ['description' => 'From a test checkout']);

        $notes = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk()->json('notes');

        $this->assertCount(20, $notes);
        $this->assertSame('Note 22', $notes[0]['description']);
        $this->assertSame('Note 3', $notes[19]['description']);
        $this->assertSame($inspection->inspection_reference, $notes[0]['inspection_reference']);
        $this->assertNotContains('An issue', array_column($notes, 'description'));
        $this->assertNotContains('From a test checkout', array_column($notes, 'description'));
    }

    // ------------------------------------------------------------------ photos

    public function test_photos_are_served_only_to_authorized_members_and_only_under_their_own_issue(): void
    {
        [$defect, $observation, $concern] = $this->photoFixtures();

        $defectPhoto = $this->get("/api/public/apparatus-issues/{$defect->id}/photo")->assertOk();
        $this->assertStringStartsWith('image/', (string) $defectPhoto->headers->get('Content-Type'));
        $this->assertSame('nosniff', $defectPhoto->headers->get('X-Content-Type-Options'));
        $this->get("/api/public/apparatus-issues/{$defect->id}/observations/{$observation->id}/photo")->assertOk();
        $this->get("/api/public/inspection-concerns/{$concern->id}/photo")->assertOk();

        $elsewhere = $this->defect(['item' => 'Different issue']);
        $this->get("/api/public/apparatus-issues/{$elsewhere->id}/observations/{$observation->id}/photo")->assertNotFound();
        $this->get("/api/public/apparatus-issues/{$elsewhere->id}/photo")->assertNotFound();
        $this->get("/api/public/apparatus-issues/{$defect->id}/observations/999999/photo")->assertNotFound();
        $this->get('/api/public/inspection-concerns/999999/photo')->assertNotFound();

        $missingFile = $this->defect(['item' => 'Photo went missing', 'photo_path' => 'defects/gone.png']);
        $this->get("/api/public/apparatus-issues/{$missingFile->id}/photo")->assertNotFound();

        $traversal = $this->defect(['item' => 'Hostile path', 'photo_path' => 'defects/../../.env']);
        $this->get("/api/public/apparatus-issues/{$traversal->id}/photo")->assertNotFound();

        $this->logoutCanonicalSession();
        $this->getJson("/api/public/apparatus-issues/{$defect->id}/photo")->assertUnauthorized();
        $this->getJson("/api/public/apparatus-issues/{$defect->id}/observations/{$observation->id}/photo")->assertUnauthorized();
        $this->getJson("/api/public/inspection-concerns/{$concern->id}/photo")->assertUnauthorized();

        $this->actingAsCanonicalUser(User::factory()->create(['account_status' => AccountStatus::Active]));
        $this->getJson("/api/public/apparatus-issues/{$defect->id}/photo")->assertForbidden();
        $this->getJson("/api/public/apparatus-issues/{$defect->id}/observations/{$observation->id}/photo")->assertForbidden();
        $this->getJson("/api/public/inspection-concerns/{$concern->id}/photo")->assertForbidden();
    }

    public function test_the_timeline_points_photos_at_the_authorized_routes(): void
    {
        [$defect, $observation] = $this->photoFixtures();
        $this->event($defect, 'reported', ['to_status' => 'reported']);
        $this->event($defect, 'observed_again', ['details' => ['notes' => 'Looked again']]);

        $timeline = $this->getJson("/api/public/apparatus-issues/{$defect->id}/history")->assertOk()->json('timeline');

        $this->assertSame("/api/public/apparatus-issues/{$defect->id}/photo", $timeline[0]['photo_url']);
        $this->assertNull($timeline[1]['photo_url'], 'The observation belongs to an inspection, not to this event');
        $this->assertStringNotContainsString('defects/', json_encode($timeline, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ cost

    public function test_the_list_costs_the_same_queries_however_long_the_histories_are(): void
    {
        $staff = User::factory()->create(['name' => 'Staff Member']);
        $defects = [];
        foreach (['One', 'Two', 'Three'] as $item) {
            $defects[] = $defect = $this->defect(['item' => $item, 'first_reported_by_user_id' => $staff->id]);
            $this->event($defect, 'reported', ['to_status' => 'reported']);
            $this->event($defect, 'acknowledged', ['from_status' => 'reported', 'to_status' => 'acknowledged', 'actor_user_id' => $staff->id]);
            $this->message($defect, $staff, 'A message', ApparatusIssueMessage::CONTEXT_LOGISTICS);
        }
        $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk();

        $short = $this->queriesFor("/api/public/apparatuses/{$this->truck->id}/findings");

        foreach ($defects as $defect) {
            for ($i = 0; $i < 40; $i++) {
                $this->event($defect, 'action_updated', ['from_status' => 'acknowledged', 'to_status' => 'acknowledged', 'actor_user_id' => $staff->id, 'member_message' => "Update {$i}"]);
                $this->event($defect, 'observed_again', ['details' => ['notes' => "Seen {$i}"]]);
            }
            for ($i = 0; $i < 10; $i++) {
                $this->message($defect, $staff, "More {$i}", ApparatusIssueMessage::CONTEXT_LOGISTICS);
            }
        }
        foreach (['Four', 'Five'] as $item) {
            $this->defect(['item' => $item]);
        }
        $long = $this->queriesFor("/api/public/apparatuses/{$this->truck->id}/findings");

        $this->assertSame($short, $long, "The list read:\n".implode("\n", $long));
        $response = $this->getJson("/api/public/apparatuses/{$this->truck->id}/findings")->assertOk();
        $this->assertSame(93, $response->json('open.0.history_count'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<string> the SQL the request ran, with bindings left out */
    private function queriesFor(string $url): array
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            // Ids inlined by the relation loaders are not part of the shape.
            $queries[] = preg_replace('/\bin \([\d?, ]+\)/', 'in (...)', $query->sql);
        });
        $this->getJson($url)->assertOk();

        return $queries;
    }

    /** @return list<string> */
    private function urls(ApparatusDefect $defect, ApparatusDefectObservation $observation, ApparatusInspectionConcern $concern): array
    {
        return [
            "/api/public/apparatuses/{$this->truck->id}/findings",
            "/api/public/apparatuses/{$this->truck->id}/findings/closed",
            "/api/public/apparatus-issues/{$defect->id}/history",
            "/api/public/apparatus-issues/{$defect->id}/photo",
            "/api/public/apparatus-issues/{$defect->id}/observations/{$observation->id}/photo",
            "/api/public/inspection-concerns/{$concern->id}/photo",
        ];
    }

    /** @return array{ApparatusDefect, ApparatusDefectObservation, ApparatusInspectionConcern} */
    private function photoFixtures(): array
    {
        Storage::disk('public')->put('defects/defect.png', base64_decode(self::PNG));
        Storage::disk('public')->put('defects/observation.png', base64_decode(self::PNG));
        Storage::disk('public')->put('defects/concern.png', base64_decode(self::PNG));
        $defect = $this->defect(['photo_path' => 'defects/defect.png']);
        $inspection = $this->inspection();
        $observation = ApparatusDefectObservation::query()->create(['apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id,
            'observation' => 'confirmed_again', 'photo_path' => 'defects/observation.png']);
        $concern = $this->concern($inspection, ['photo_path' => 'defects/concern.png']);

        return [$defect, $observation, $concern];
    }

    private function apparatus(string $unit, string $vehicle, string $template): Apparatus
    {
        return Apparatus::query()->create([
            'name' => "Engine {$unit}", 'unit_id' => $unit, 'designation' => $unit, 'type' => 'Engine', 'slug' => strtolower($unit),
            'vehicle_number' => $vehicle, 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => $template,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function defect(array $attributes = []): ApparatusDefect
    {
        $apparatus = $attributes['apparatus'] ?? $this->truck;
        unset($attributes['apparatus']);

        return ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Deck',
            'item' => 'Hinge '.(++$this->tick),
            'status' => 'open',
            'issue_type' => 'damaged',
            'notes' => 'Seeded issue',
            'action_status' => 'reported',
            'first_reported_at' => CarbonImmutable::parse('2026-10-30 12:00:00'),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(ApparatusDefect $defect, string $type, array $attributes = []): ApparatusDefectEvent
    {
        $time = CarbonImmutable::parse('2026-10-31 10:00:00')->addSeconds(++$this->tick);

        return ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->id,
            'event_type' => $type,
            'created_at' => $time,
            'updated_at' => $time,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(ApparatusDefect $defect, User $author, string $body, string $context, array $attributes = []): ApparatusIssueMessage
    {
        $time = CarbonImmutable::parse('2026-10-31 10:00:00')->addSeconds(++$this->tick);

        return ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $defect->id,
            'author_user_id' => $author->id,
            'author_context' => $context,
            'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
            'body' => $body,
            'created_at' => $time,
            'updated_at' => $time,
            ...$attributes,
        ]);
    }

    private function inspection(): ApparatusInspection
    {
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->truck->id, 'actor_user_id' => $this->member->id, 'operator_name' => 'History Member', 'rank' => 'Firefighter', 'review_status' => 'approved',
        ]);
        $inspection->update(['inspection_reference' => 'INS-E2-2026-10-31-'.str_pad((string) $inspection->id, 6, '0', STR_PAD_LEFT)]);

        return $inspection;
    }

    /** @param array<string, mixed> $attributes */
    private function concern(ApparatusInspection $inspection, array $attributes = []): ApparatusInspectionConcern
    {
        return ApparatusInspectionConcern::query()->create([
            'apparatus_id' => $this->truck->id,
            'apparatus_inspection_id' => $inspection->id,
            'reporter_user_id' => $this->member->id,
            'reporter_name' => 'History Member',
            'client_id' => sprintf('91919191-9191-4191-8191-%012d', ++$this->tick),
            'kind' => 'note',
            'description' => 'A note',
            ...$attributes,
        ]);
    }
}
