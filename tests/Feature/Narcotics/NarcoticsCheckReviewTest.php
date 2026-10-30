<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Models\User;
use App\Services\Narcotics\NarcoticsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** The state machine: pending -> approved | rejected, archive, resubmission, races. */
final class NarcoticsCheckReviewTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    private User $secondCaptain5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
        $this->secondCaptain5 = $this->emsMember('Captain', 'Second Five');
        $this->emsCaptain5Seat($this->secondCaptain5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function events(NarcoticsCheck $check, string $action): int
    {
        return NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->where('action', $action)->count();
    }

    // ---------------------------------------------------------------- inbox

    public function test_the_inbox_lists_pending_checks_oldest_first_with_the_member_time_note_and_photo(): void
    {
        Carbon::setTestNow('2026-10-30 09:00:00');
        $older = $this->submitOk($this->member, ['note' => 'Box 1']);
        Carbon::setTestNow('2026-10-30 10:30:00');
        $newer = $this->submitOk($this->otherMember);
        Carbon::setTestNow();

        $list = $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk();

        $this->assertSame([$older->uuid, $newer->uuid], array_column($list->json('data'), 'id'));
        $this->assertSame(2, $list->json('meta.pending_count'));
        $first = $list->json('data.0');
        $this->assertSame('Pat Member', $first['submitter_name']);
        $this->assertSame('Firefighter', $first['submitter_rank']);
        $this->assertSame('Pending review', $first['status_label']);
        $this->assertSame('Box 1', $first['member_note']);
        $this->assertTrue($first['has_note']);
        $this->assertStringStartsWith('2026-10-30T09:00:00', $first['submitted_at']);
        $this->assertSame("/api/narcotics-checks/{$older->uuid}/image/preview", $first['image']['preview_url']);
        $this->assertSame([], $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive')->json('data'));
    }

    // -------------------------------------------------------------- approve

    public function test_one_approval_archives_the_check_records_who_and_when_and_tells_the_member(): void
    {
        $check = $this->submitOk($this->member);
        Carbon::setTestNow('2026-10-30 11:00:00');

        $this->approve($this->captain5, $check)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.status_label', 'Approved')
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.decided_by_name', 'Cap Five')
            ->assertJsonPath('data.decided_by_rank', 'Captain')
            ->assertJsonPath('meta.changed', true);

        $row = $check->fresh();
        $this->assertSame(NarcoticsCheck::APPROVED, $row->status);
        $this->assertSame($this->captain5->id, $row->decided_by_user_id);
        $this->assertSame('2026-10-30 11:00:00', $row->decided_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 11:00:00', $row->archived_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($row->decision_note);

        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->where('action', 'approved')->sole();
        $this->assertSame('pending', $event->from_status);
        $this->assertSame('approved', $event->to_status);
        $this->assertSame('captain5', $event->actor_context);
        $this->assertSame('Cap Five', $event->actor_name);

        // It left the inbox and is in the searchable archive.
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.pending_count', 0);
        $archive = $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive')->assertOk();
        $this->assertSame([$check->uuid], array_column($archive->json('data'), 'id'));

        // The member sees it, and was told once.
        $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$check->uuid}")
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.can_resubmit', false);
        $this->assertSame(1, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION));
        $this->assertSame(0, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_DECISION), 'the actor is never notified');
        Carbon::setTestNow();
    }

    public function test_a_second_captain_5_approving_the_same_check_changes_nothing_and_notifies_nobody_twice(): void
    {
        $check = $this->submitOk($this->member);

        $this->approve($this->captain5, $check)->assertOk()->assertJsonPath('meta.changed', true);
        $decidedAt = $check->fresh()->decided_at;
        $this->approve($this->secondCaptain5, $check)
            ->assertOk()
            ->assertJsonPath('meta.changed', false)
            ->assertJsonPath('data.decided_by_name', 'Cap Five');

        $this->assertSame($this->captain5->id, $check->fresh()->decided_by_user_id, 'the first decision stands');
        $this->assertEquals($decidedAt, $check->fresh()->decided_at);
        $this->assertSame(1, $this->events($check, 'approved'));
        $this->assertSame(1, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION));
        $this->assertSame(1, DB::table('inspection_notification_deliveries')->where('recipient_user_id', $this->member->id)->where('notification_type', NarcoticsNotifier::KIND_DECISION)->count());
    }

    public function test_the_opposite_decision_after_a_decision_is_a_plain_conflict_that_changes_nothing(): void
    {
        $approved = $this->submitOk($this->member);
        $rejected = $this->submitOk($this->member);
        $this->approve($this->captain5, $approved)->assertOk();
        $this->reject($this->captain5, $rejected, 'Label unreadable')->assertOk();

        $this->reject($this->secondCaptain5, $approved, 'changed my mind')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'already_decided')
            ->assertJsonPath('error.message', 'This check was already approved by Cap Five. Nothing was changed.');
        $this->approve($this->secondCaptain5, $rejected)->assertStatus(409)->assertJsonPath('error.code', 'already_decided');

        $this->assertSame(NarcoticsCheck::APPROVED, $approved->fresh()->status);
        $this->assertNull($approved->fresh()->decision_note);
        $this->assertSame(NarcoticsCheck::REJECTED, $rejected->fresh()->status);
        $this->assertSame('Label unreadable', $rejected->fresh()->decision_note);
    }

    public function test_two_captains_racing_inside_the_same_instant_produce_exactly_one_decision(): void
    {
        $check = $this->submitOk($this->member);
        $raced = false;
        // Captain A's approval lands between Captain B's read and Captain B's conditional update.
        DB::beforeExecuting(function (string $query) use (&$raced, $check): void {
            if ($raced || ! str_starts_with($query, 'update "narcotics_checks"')) {
                return;
            }
            $raced = true;
            DB::table('narcotics_checks')->where('id', $check->id)->update([
                'status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $this->captain5->id,
                'decided_by_name' => 'Cap Five', 'archived_at' => now(),
            ]);
        });

        $this->approve($this->secondCaptain5, $check)
            ->assertOk()
            ->assertJsonPath('meta.changed', false)
            ->assertJsonPath('data.decided_by_name', 'Cap Five');

        $this->assertTrue($raced, 'the race must actually have been staged');
        $this->assertSame(0, $this->events($check, 'approved'), 'the loser writes no audit row');
        $this->assertSame(0, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION), 'the loser sends no notification');
        $this->assertSame($this->captain5->id, $check->fresh()->decided_by_user_id);
    }

    public function test_a_reject_that_loses_the_race_to_an_approval_is_a_conflict_not_a_second_decision(): void
    {
        $check = $this->submitOk($this->member);
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced, $check): void {
            if ($raced || ! str_starts_with($query, 'update "narcotics_checks"')) {
                return;
            }
            $raced = true;
            DB::table('narcotics_checks')->where('id', $check->id)->update(['status' => 'approved', 'decided_at' => now(), 'decided_by_name' => 'Cap Five', 'archived_at' => now()]);
        });

        $this->reject($this->secondCaptain5, $check, 'too late')->assertStatus(409)->assertJsonPath('error.code', 'already_decided');

        // (The staged approval ran inside the loser's transaction, which the 409 rolled back; in production it is another connection's committed write.)
        $this->assertNull($check->fresh()->decision_note);
        $this->assertSame(0, $this->events($check, 'rejected'));
        $this->assertSame(0, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION));
    }

    public function test_approving_an_unknown_check_is_a_404(): void
    {
        $this->staffPost($this->captain5, '/api/narcotics-checks/staff/checks/'.Str::uuid().'/approve')->assertNotFound();
    }

    // --------------------------------------------------------------- reject

    public function test_rejecting_with_an_explanation_archives_it_and_the_member_can_read_why(): void
    {
        $check = $this->submitOk($this->member, ['note' => 'All good']);

        $this->reject($this->captain5, $check, '  Seal number is not visible.  ')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.decision_note', 'Seal number is not visible.');

        $mine = $this->actingAs($this->member)->getJson("/api/narcotics-checks/mine/{$check->uuid}")->assertOk();
        $mine->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_label', 'Rejected')
            ->assertJsonPath('data.decision_note', 'Seal number is not visible.')
            ->assertJsonPath('data.can_resubmit', true);
        $this->assertSame(['submitted', 'rejected'], array_column($mine->json('data.history'), 'action'));

        $archive = $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&status=rejected')->assertOk();
        $this->assertSame([$check->uuid], array_column($archive->json('data'), 'id'));
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&status=approved')->assertJsonCount(0, 'data');
    }

    public function test_the_explanation_is_optional_and_capped(): void
    {
        $plain = $this->submitOk($this->member);
        $this->reject($this->captain5, $plain)->assertOk()->assertJsonPath('data.decision_note', null);

        $long = $this->submitOk($this->member);
        $this->reject($this->captain5, $long, str_repeat('x', 501))->assertStatus(422)->assertJsonPath('error.message', 'The explanation can be at most 500 characters.');
        $this->assertSame('pending', $long->fresh()->status);
    }

    public function test_rejecting_twice_keeps_the_first_explanation(): void
    {
        $check = $this->submitOk($this->member);
        $this->reject($this->captain5, $check, 'First reason')->assertOk();

        $this->reject($this->secondCaptain5, $check, 'Second reason')->assertOk()->assertJsonPath('meta.changed', false)->assertJsonPath('data.decision_note', 'First reason');
        $this->assertSame(1, $this->events($check, 'rejected'));
    }

    // ----------------------------------------------------------- resubmission

    public function test_a_rejected_check_is_answered_by_a_new_one_and_the_history_is_untouched(): void
    {
        $rejected = $this->submitOk($this->member, ['note' => 'First try']);
        $this->reject($this->captain5, $rejected, 'Blurry')->assertOk();
        $before = $rejected->fresh()->toArray();
        $beforeEvents = NarcoticsCheckEvent::query()->where('narcotics_check_id', $rejected->id)->pluck('id')->all();

        $again = $this->submitOk($this->member, ['note' => 'Retaken', 'resubmission_of' => $rejected->uuid]);

        $this->assertNotSame($rejected->id, $again->id);
        $this->assertSame($rejected->id, $again->resubmission_of_id);
        $this->assertSame('pending', $again->status);
        $this->assertSame($before, $rejected->fresh()->toArray(), 'the rejected record is never edited');
        $this->assertSame($beforeEvents, NarcoticsCheckEvent::query()->where('narcotics_check_id', $rejected->id)->pluck('id')->all());

        $mine = $this->actingAs($this->member)->getJson('/api/narcotics-checks/mine')->json('data');
        $byId = array_column($mine, null, 'id');
        $this->assertSame($rejected->uuid, $byId[$again->uuid]['resubmission_of']);
        $this->assertSame($again->uuid, $byId[$rejected->uuid]['resubmitted_as']);
        $this->assertFalse($byId[$rejected->uuid]['can_resubmit'], 'a rejected check is answered once');

        // Captain 5 is told it is a second try; the new one is reviewed like any other.
        $told = collect([$this->captain5, $this->secondCaptain5])->flatMap(fn (User $captain) => \Illuminate\Support\Facades\Notification::sent($captain, \App\Notifications\Inspections\InspectionEventNotification::class))
            ->filter(fn ($n): bool => $n->eventKey === 'narcotics:check:'.$again->id.':submitted');
        $this->assertGreaterThanOrEqual(1, $told->count());
        $this->assertStringContainsString('sent again after a rejection', $told->first()->body);
        $this->approve($this->captain5, $again)->assertOk();
        $this->assertSame('rejected', $rejected->fresh()->status);
    }

    public function test_only_your_own_rejected_unanswered_check_can_be_resubmitted(): void
    {
        $pending = $this->submitOk($this->member);
        $approved = $this->submitOk($this->member);
        $this->approve($this->captain5, $approved)->assertOk();
        $rejected = $this->submitOk($this->member);
        $this->reject($this->captain5, $rejected)->assertOk();

        foreach ([$pending, $approved] as $notRejected) {
            $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['resubmission_of' => $notRejected->uuid])
                ->assertStatus(422)->assertJsonPath('error.code', 'cannot_resubmit');
        }
        // Someone else's rejected check looks like it does not exist.
        $this->sendCheck($this->otherMember, $this->upload($this->jpegBytes()), ['resubmission_of' => $rejected->uuid])
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_resubmit');
        $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['resubmission_of' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_resubmit');

        $this->submitOk($this->member, ['resubmission_of' => $rejected->uuid]);
        $filesBefore = $this->storedFiles();
        $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['resubmission_of' => $rejected->uuid])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_resubmitted');
        $this->assertSame($filesBefore, $this->storedFiles(), 'a refused resubmission leaves no photos behind');
    }

    public function test_a_retried_resubmission_with_the_same_submission_id_is_replayed_not_refused(): void
    {
        $rejected = $this->submitOk($this->member);
        $this->reject($this->captain5, $rejected)->assertOk();
        $id = (string) Str::uuid();

        $first = $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id, 'resubmission_of' => $rejected->uuid])->assertStatus(201);
        $second = $this->sendCheck($this->member, $this->upload($this->jpegBytes()), ['client_submission_id' => $id, 'resubmission_of' => $rejected->uuid])->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, NarcoticsCheck::query()->count());
    }

    // ----------------------------------------------------------------- archive

    public function test_the_archive_is_searchable_by_member_note_decision_and_date(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $monday = $this->submitOk($this->member, ['note' => 'Cabinet B tamper seal intact']);
        Carbon::setTestNow('2026-10-28 12:00:00');
        $friday = $this->submitOk($this->otherMember, ['note' => 'Routine']);
        $this->approve($this->captain5, $monday)->assertOk();
        $this->reject($this->captain5, $friday, 'Photo too dark to read the count')->assertOk();
        Carbon::setTestNow();

        $search = fn (string $query): array => array_column(
            $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&'.$query)->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$friday->uuid, $monday->uuid], $search(''), 'newest decision first');
        $this->assertSame([$monday->uuid], $search('q=pat'));
        $this->assertSame([$monday->uuid], $search('q=TAMPER'));
        $this->assertSame([$friday->uuid], $search('q=too+dark'));
        $this->assertSame([$friday->uuid], $search('q=olive'));
        $this->assertSame([], $search('q=no-such-thing'));
        $this->assertSame([], $search('q=100%25'), 'a percent sign is text, not a wildcard');
        $this->assertSame([$monday->uuid], $search('date_from=2026-10-20&date_to=2026-10-21'));
        $this->assertSame([$friday->uuid], $search('date_from=2026-10-25'));
        $this->actingAs($this->captain5)->getJson('/api/narcotics-checks/staff/checks?scope=archive&date_from=2026-10-30&date_to=2026-10-01')->assertStatus(422);
    }

    // -------------------------------------------------------------------- audit

    public function test_each_reviewer_opening_a_check_is_recorded_once(): void
    {
        $check = $this->submitOk($this->member);

        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$check->uuid}")->assertOk()->assertJsonPath('data.history.0.action', 'submitted');
        $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$check->uuid}")->assertOk();
        $this->assertSame(1, $this->events($check, 'viewed'));

        $this->actingAs($this->secondCaptain5)->getJson("/api/narcotics-checks/staff/checks/{$check->uuid}")->assertOk();
        $this->assertSame(2, $this->events($check, 'viewed'));

        $detail = $this->actingAs($this->captain5)->getJson("/api/narcotics-checks/staff/checks/{$check->uuid}")->json('data.history');
        $this->assertSame(['submitted', 'viewed', 'viewed'], array_column($detail, 'action'));
    }

    public function test_history_rows_cannot_be_edited_or_deleted(): void
    {
        $check = $this->submitOk($this->member);
        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->update(['actor_name' => 'Someone else']);
    }
}
