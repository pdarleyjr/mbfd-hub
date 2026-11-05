<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\PersonnelRequestStatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\MemberActivityRead;
use App\Models\PersonnelRequest;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Services\Activity\MemberActivityFeed;
use App\Services\Activity\MemberActivityItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** My activity: ownership, privacy, unread, ordering, retention and nesting (docs/unified-activity-and-ownership.md section 5). */
final class MemberActivityFeedTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private User $me;

    private User $other;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->me = $this->member('Olive Owner');
        $this->other = $this->member('Other Member');
        $this->staff = $this->reviewer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<MemberActivityItem> */
    private function card(?User $user = null): array
    {
        return app(MemberActivityFeed::class)->forCard($user ?? $this->me);
    }

    /** @return list<string> */
    private function keys(array $items): array
    {
        return array_map(fn (MemberActivityItem $item): string => $item->key, $items);
    }

    private function inspection(?User $by = null, string $at = '2026-11-04 09:00:00', string $review = 'approved'): ApparatusInspection
    {
        $inspection = $this->inspectionBy($this->rig(), $by ?? $this->me, $at);
        DB::table('apparatus_inspections')->where('id', $inspection->id)->update(['review_status' => $review]);

        return $inspection->fresh();
    }

    private function stationRequest(?User $by, string $number = 'SR-TEST-1'): StationRequest
    {
        $this->fixtureCounter++;
        $station = Station::query()->create(['station_number' => 500 + $this->fixtureCounter, 'name' => 'Station X'.$this->fixtureCounter, 'address' => '1 Test Way', 'is_active' => true]);

        return StationRequest::query()->create([
            'station_id' => $station->id, 'actor_user_id' => $by?->id, 'requested_by_employee_id' => $by?->employee_profile_id,
            'requester_name_snapshot' => $by?->name ?? 'Nobody', 'request_type' => 'repair', 'title' => 'Dripping faucet '.$number,
            'description' => 'INTERNAL-DESCRIPTION', 'request_number' => $number, 'status' => 'pending',
        ]);
    }

    private function personnelRequestFor(User $user, string $number = 'UR-TEST-1'): PersonnelRequest
    {
        $id = (string) str()->ulid();

        return PersonnelRequest::query()->create([
            'public_id' => $id, 'request_number' => $number, 'type' => 'uniform', 'status' => PersonnelRequestStatus::Pending,
            'beneficiary_employee_id' => $user->employee_profile_id, 'requester_employee_id' => $user->employee_profile_id,
            'beneficiary_name' => $user->name, 'beneficiary_employee_number' => 'E1', 'requester_name' => $user->name,
            'requester_employee_number' => 'E1', 'idempotency_key' => 'k-'.$id,
        ]);
    }

    private function note(StationRequest $request, string $text, ?User $by, bool $automated = false, string $at = '2026-11-04 10:00:00'): void
    {
        $update = new StationRequestUpdate;
        $update->forceFill([
            'station_request_id' => $request->id, 'status' => 'pending', 'public_note' => $text, 'internal_note' => 'INTERNAL-NOTE-SECRET',
            'changed_by_user_id' => $by?->id, 'created_at' => $at, 'updated_at' => $at,
            'automation_intake_id' => $automated ? random_int(1, 999999) : null,
        ])->save();
    }

    private function say(ApparatusDefect $defect, string $context, string $body, string $visibility = 'member', string $at = '2026-11-04 10:00:00'): void
    {
        ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $defect->id, 'author_user_id' => $context === 'assistant' ? null : $this->staff->id,
            'author_context' => $context, 'visibility' => $visibility, 'body' => $body, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_only_the_members_own_items_appear_and_nothing_internal_leaks(): void
    {
        Exceptions::fake();
        $mine = $this->stationRequest($this->me, 'SR-MINE');
        $theirs = $this->stationRequest($this->other, 'SR-THEIRS');
        $this->note($mine, 'PUBLIC-TO-ME', $this->staff);
        $this->note($theirs, 'PUBLIC-TO-OTHER', $this->staff);
        $myPersonnel = $this->personnelRequestFor($this->me, 'UR-MINE');
        $this->personnelRequestFor($this->other, 'UR-THEIRS');

        $myInspection = $this->inspection();
        $theirInspection = $this->inspection($this->other);
        $defect = $this->openDefect($myInspection->apparatus, $myInspection, $this->me);
        $this->say($defect, 'logistics', 'VISIBLE-STAFF-WORDS');
        $this->say($defect, 'logistics', 'INTERNAL-ISSUE-SECRET', 'internal');
        $this->say($defect, 'member', 'CREW-REPLY-NOT-SHOWN');
        $this->openDefect($theirInspection->apparatus, $theirInspection, $this->other, 'Other Item');

        $items = $this->card();
        $html = json_encode($items);

        $keys = $this->keys($items);
        $this->assertContains('station_request:'.$mine->id, $keys);
        $this->assertContains('personnel_request:'.$myPersonnel->id, $keys);
        $this->assertContains('inspection:'.$myInspection->id, $keys);
        $this->assertNotContains('station_request:'.$theirs->id, $keys);
        $this->assertNotContains('inspection:'.$theirInspection->id, $keys);
        foreach (['SR-THEIRS', 'UR-THEIRS', 'PUBLIC-TO-OTHER', 'INTERNAL-NOTE-SECRET', 'INTERNAL-ISSUE-SECRET', 'CREW-REPLY-NOT-SHOWN', 'INTERNAL-DESCRIPTION', 'Other Item'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $html, $secret);
        }
        $this->assertStringContainsString('VISIBLE-STAFF-WORDS', (string) $html);
        Exceptions::assertNothingReported();
    }

    public function test_opening_another_members_key_is_not_found_and_records_nothing(): void
    {
        $theirs = $this->stationRequest($this->other, 'SR-THEIRS');
        $theirInspection = $this->inspection($this->other);

        foreach (['station_request:'.$theirs->id, 'inspection:'.$theirInspection->id, 'message:99999', 'defect:1'] as $key) {
            $this->actingAs($this->me)->get('/employee/activity/open/'.$key)->assertNotFound();
        }
        $this->assertSame(0, MemberActivityRead::query()->count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/employee/activity/open/inspection:1')->assertRedirect(route('login'));
    }

    public function test_an_automated_receipt_never_marks_an_item_unread(): void
    {
        $request = $this->stationRequest($this->me);
        $this->note($request, 'We received your request.', null, automated: true);

        $item = $this->card()[0];

        $this->assertFalse($item->unread);
        $this->assertTrue($item->latestUpdate->automated);
        $this->assertSame('We received your request.', $item->latestUpdate->excerpt);
    }

    public function test_a_staff_reply_marks_unread_and_opening_the_item_clears_it(): void
    {
        $request = $this->stationRequest($this->me);
        $this->note($request, 'A tech is scheduled for Friday.', $this->staff, at: '2026-11-04 10:00:00');
        $this->assertTrue($this->card()[0]->unread);

        $this->actingAs($this->me)->get('/employee/activity/open/station_request:'.$request->id)
            ->assertRedirect('/daily/stations/'.$request->station_id);
        $this->assertFalse($this->card()[0]->unread);

        Carbon::setTestNow('2026-11-04 13:00:00');
        $this->note($request, 'Tech rescheduled to Monday.', $this->staff, at: '2026-11-04 12:30:00');
        $this->assertTrue($this->card()[0]->unread);
    }

    public function test_the_members_own_actions_are_never_unread(): void
    {
        $request = $this->stationRequest($this->me);
        $this->note($request, 'My own follow-up note.', $this->me);

        $item = $this->card()[0];

        $this->assertFalse($item->unread);
        $this->assertNull($item->latestUpdate);
    }

    public function test_a_clarification_request_needs_a_reply_and_sorts_first(): void
    {
        $recent = $this->stationRequest($this->me, 'SR-RECENT');
        $this->note($recent, 'Newer staff note.', $this->staff, at: '2026-11-04 11:30:00');
        $inspection = $this->inspection();
        DB::table('apparatus_inspection_exceptions')->insert($this->exceptionRow($inspection));

        $items = $this->card();

        $this->assertSame('inspection:'.$inspection->id, $items[0]->key);
        $this->assertTrue($items[0]->needsResponse);
        $this->assertSame('station_request:'.$recent->id, $items[1]->key);
    }

    public function test_order_is_needs_reply_then_unread_then_open_then_routine(): void
    {
        $routine = $this->inspection(at: '2026-11-04 11:00:00');
        $open = $this->stationRequest($this->me, 'SR-OPEN');
        $unread = $this->stationRequest($this->me, 'SR-UNREAD');
        $this->note($unread, 'Staff says hello.', $this->staff, at: '2026-11-04 08:00:00');
        $needs = $this->inspection(at: '2026-11-04 07:00:00');
        DB::table('apparatus_inspection_exceptions')->insert($this->exceptionRow($needs));

        $order = $this->keys($this->card());

        $this->assertSame([
            'inspection:'.$needs->id, 'station_request:'.$unread->id, 'station_request:'.$open->id, 'inspection:'.$routine->id,
        ], $order);
    }

    public function test_retention_routine_48_hours_closed_72_hours_open_until_closed(): void
    {
        $freshRoutine = $this->inspection(at: '2026-11-03 13:00:00');
        $staleRoutine = $this->inspection(at: '2026-11-02 11:00:00');
        $freshClosed = $this->stationRequest($this->me, 'SR-FRESH');
        $staleClosed = $this->stationRequest($this->me, 'SR-STALE');
        $oldOpen = $this->stationRequest($this->me, 'SR-OLDOPEN');
        DB::table('station_requests')->where('id', $freshClosed->id)->update(['status' => 'completed', 'completed_at' => '2026-11-02 13:00:00']);
        DB::table('station_requests')->where('id', $staleClosed->id)->update(['status' => 'completed', 'completed_at' => '2026-11-01 11:00:00', 'created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-01 11:00:00']);
        DB::table('station_requests')->where('id', $oldOpen->id)->update(['created_at' => '2026-09-20 08:00:00', 'updated_at' => '2026-09-20 08:00:00']);

        $keys = $this->keys($this->card());

        $this->assertContains('inspection:'.$freshRoutine->id, $keys);
        $this->assertNotContains('inspection:'.$staleRoutine->id, $keys);
        $this->assertContains('station_request:'.$freshClosed->id, $keys);
        $this->assertNotContains('station_request:'.$staleClosed->id, $keys);
        $this->assertContains('station_request:'.$oldOpen->id, $keys);

        $page = $this->keys(app(MemberActivityFeed::class)->forPage($this->me));
        $this->assertContains('inspection:'.$staleRoutine->id, $page);
        $this->assertContains('station_request:'.$staleClosed->id, $page);
    }

    public function test_the_card_shows_at_most_ten_parents(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->stationRequest($this->me, 'SR-'.$i);
        }

        $this->assertCount(10, $this->card());
        $this->assertCount(14, app(MemberActivityFeed::class)->forPage($this->me));
    }

    public function test_an_inspections_issues_nest_under_it_and_their_updates_bubble_up(): void
    {
        $inspection = $this->inspection();
        $defect = $this->openDefect($inspection->apparatus, $inspection, $this->me);
        $this->say($defect, 'captain5', 'Captain 5 has the replacement.');

        $items = $this->card();

        $this->assertSame(['inspection:'.$inspection->id], $this->keys($items));
        $parent = $items[0];
        $this->assertSame(['defect:'.$defect->id], $this->keys($parent->children));
        $this->assertTrue($parent->unread);
        $this->assertTrue($parent->isOpen);
        $this->assertFalse($parent->routine);
        $this->assertSame('Captain 5', $parent->latestUpdate->authorLabel);
        $this->assertStringContainsString('Captain 5 has the replacement.', $parent->latestUpdate->excerpt);

        $this->actingAs($this->me)->get('/employee/activity/open/inspection:'.$inspection->id)->assertRedirect();
        $this->assertFalse($this->card()[0]->unread);
    }

    public function test_a_defect_from_someone_elses_inspection_is_listed_on_its_own_and_opens_the_issue_page(): void
    {
        $theirs = $this->inspection($this->other);
        $defect = $this->openDefect($theirs->apparatus, $theirs, $this->other);
        $this->participant($defect, $this->me, 'confirmed', null);

        $items = $this->card();

        $this->assertSame(['defect:'.$defect->id], $this->keys($items));
        $this->assertStringContainsString('/daily/vehicle-inspections/'.$theirs->apparatus->slug.'/issues', $items[0]->url);
    }

    public function test_a_department_message_on_an_inspection_is_shown_and_a_clean_inspection_is_routine(): void
    {
        $clean = $this->inspection();
        $held = $this->inspection(review: 'pending_review');

        $byKey = [];
        foreach ($this->card() as $item) {
            $byKey[$item->key] = $item;
        }

        $this->assertTrue($byKey['inspection:'.$clean->id]->routine);
        $this->assertFalse($byKey['inspection:'.$clean->id]->isOpen);
        $this->assertTrue($byKey['inspection:'.$held->id]->isOpen);
        $this->assertSame('Waiting for Logistics review', $byKey['inspection:'.$held->id]->statusLabel);
    }

    public function test_the_page_filter_matches_subject_and_description_words(): void
    {
        $this->stationRequest($this->me, 'SR-FAUCET');
        $this->personnelRequestFor($this->me, 'UR-BOOTS');
        $feed = app(MemberActivityFeed::class);
        $all = $feed->forPage($this->me);

        $this->assertCount(1, $feed->filter($all, 'sr-faucet'));
        $this->assertCount(1, $feed->filter($all, 'dripping faucet'));
        $this->assertCount(0, $feed->filter($all, 'no such thing'));
        $this->assertCount(1, $feed->filter($all, '', 'Station request'));
        $this->assertSame(['Station request', 'Uniform Request'], $feed->categories($all));
    }

    public function test_excerpts_are_capped_at_160_characters(): void
    {
        $request = $this->stationRequest($this->me);
        $this->note($request, str_repeat('word ', 80), $this->staff);

        $this->assertLessThanOrEqual(160, mb_strlen($this->card()[0]->latestUpdate->excerpt));
    }

    public function test_a_crew_resolution_is_not_labelled_as_the_department(): void
    {
        $inspection = $this->inspection();
        $defect = $this->openDefect($inspection->apparatus, $inspection, $this->me);
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->id, 'actor_user_id' => $this->other->id, 'event_type' => 'resolved',
            'details' => ['member_resolution' => true], 'created_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-04 10:00:00',
        ]);

        $child = $this->card()[0]->children[0];

        $this->assertSame('A crew member', $child->latestUpdate->authorLabel);
    }

    public function test_an_inventory_correction_question_needs_a_reply_and_hides_internal_notes(): void
    {
        $rig = $this->rig();
        $id = DB::table('apparatus_inventory_corrections')->insertGetId([
            'client_request_id' => (string) str()->uuid(), 'payload_hash' => str_repeat('a', 64), 'apparatus_id' => $rig->id,
            'reporter_user_id' => $this->me->id, 'reason' => 'moved', 'target_snapshot' => json_encode(['name' => 'Halligan bar']),
            'checklist_type' => 'engine', 'checklist_version' => 'v1', 'status' => 'clarification_requested',
            'created_at' => '2026-11-04 08:00:00', 'updated_at' => '2026-11-04 08:00:00',
        ]);
        foreach ([['member', 'Which compartment now?'], ['internal', 'INTERNAL-CORRECTION-SECRET']] as [$visibility, $body]) {
            DB::table('apparatus_inventory_correction_events')->insert([
                'apparatus_inventory_correction_id' => $id, 'actor_user_id' => $this->staff->id, 'event_type' => 'clarification_requested',
                'visibility' => $visibility, 'author_context' => 'logistics', 'body' => $body,
                'created_at' => '2026-11-04 09:00:00', 'updated_at' => '2026-11-04 09:00:00',
            ]);
        }

        $item = $this->card()[0];

        $this->assertSame('inventory_correction:'.$id, $item->key);
        $this->assertTrue($item->needsResponse);
        $this->assertTrue($item->unread);
        $this->assertSame('Which compartment now?', $item->latestUpdate->excerpt);
        $this->assertStringNotContainsString('INTERNAL-CORRECTION-SECRET', (string) json_encode($item));
    }

    public function test_a_support_services_thread_is_unread_for_a_human_reply_but_not_for_the_assistant(): void
    {
        $thread = DB::table('employee_logistics_threads')->insertGetId([
            'member_user_id' => $this->me->id, 'member_name' => 'Olive Owner', 'subject' => 'Boots', 'message_count' => 2,
            'last_message_side' => 'assistant', 'last_message_at' => '2026-11-04 09:00:00', 'created_at' => '2026-11-04 08:00:00', 'updated_at' => '2026-11-04 09:00:00',
        ]);
        $say = fn (string $side, ?int $author, string $body, string $at): int => (int) DB::table('employee_logistics_messages')->insertGetId([
            'thread_id' => $thread, 'author_user_id' => $author, 'author_side' => $side, 'author_name' => 'x', 'body' => $body,
            'client_request_id' => (string) str()->uuid(), 'created_at' => $at, 'updated_at' => $at,
        ]);
        $say('assistant', null, 'Received.', '2026-11-04 09:00:00');

        $item = $this->card()[0];
        $this->assertSame('message:'.$thread, $item->key);
        $this->assertFalse($item->unread);
        $this->assertTrue($item->latestUpdate->automated);

        $reply = $say('logistics', $this->staff->id, 'Your boots are in.', '2026-11-04 10:00:00');
        $this->assertTrue($this->card()[0]->unread);

        DB::table('employee_logistics_threads')->where('id', $thread)->update(['member_read_message_id' => $reply]);
        $this->assertFalse($this->card()[0]->unread);
    }

    public function test_a_stretcher_report_follows_its_captain_5_issue(): void
    {
        $inspection = $this->inspection();
        $defect = $this->openDefect($inspection->apparatus, $inspection, $this->me, 'Stretcher');
        DB::table('apparatus_defects')->where('id', $defect->id)->update(['compartment_key' => 'stretcher_report']);
        $report = DB::table('stretcher_issue_reports')->insertGetId([
            'client_submission_id' => (string) str()->uuid(), 'apparatus_id' => $inspection->apparatus_id, 'apparatus_defect_id' => $defect->id,
            'reporter_user_id' => $this->me->id, 'problem' => 'battery', 'description' => 'Battery will not hold a charge.',
            'created_at' => '2026-11-04 09:00:00', 'updated_at' => '2026-11-04 09:00:00',
        ]);
        $this->say($defect, 'captain5', 'Swapping the battery today.');

        $keys = $this->keys($this->card());
        $item = collect($this->card())->firstWhere('key', 'stretcher_report:'.$report);

        $this->assertNotContains('defect:'.$defect->id, $keys);
        $this->assertTrue($item->isOpen);
        $this->assertTrue($item->unread);
        $this->assertSame('Captain 5', $item->ownerLabel);
        $this->assertSame('Swapping the battery today.', $item->latestUpdate->excerpt);
    }

    /** @return array<string, mixed> */
    private function exceptionRow(ApparatusInspection $inspection): array
    {
        return [
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $inspection->apparatus_id, 'field' => 'engine_hours',
            'reason' => 'meter_decrease', 'status' => 'revision_requested', 'blocks_acceptance' => true,
            'created_at' => '2026-11-04 08:00:00', 'updated_at' => '2026-11-04 08:00:00',
        ];
    }
}
