<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\InspectionConcernRecorder;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Support\Fleet\ScheduledDutyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notes, damage and reports about known issues sent with a Daily Checkout (docs/apparatus-issue-history.md section 3),
 * using the payload the real client posts.
 */
final class InspectionConcernsTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMISSION = '31313131-3131-4131-8131-313131313131';

    private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $member;

    private Apparatus $boat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->member = $this->actingAsCanonicalFixture('E01-CONCERNS', 'Concern Reporter');
        $this->boat = $this->makeFireBoat6();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_note_is_only_its_row_and_leaves_readiness_alone(): void
    {
        $payload = $this->payload([$this->concern('note', 'The lockbox key sticks in cold weather', 'Port side')]);

        $this->submit($payload)->assertCreated();

        $note = ApparatusInspectionConcern::query()->sole();
        $this->assertSame('note', $note->kind);
        $this->assertNull($note->apparatus_defect_id);
        $this->assertSame($this->boat->id, $note->apparatus_id);
        $this->assertSame($this->member->id, $note->reporter_user_id);
        $this->assertSame($this->member->display_name ?: $this->member->name, $note->reporter_name);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_defect_events', 0);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame(0, ApparatusDefect::query()->criticalForReadiness()->count());
        $this->assertSame('In Service', $this->boat->fresh()->status);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame('accepted', $inspection->processing_status);
        $this->assertSame('approved', $inspection->review_status);

        $this->getJson("/api/public/apparatuses/{$this->boat->id}/findings")->assertOk()
            ->assertJsonPath('open', [])
            ->assertJsonPath('notes.0.description', 'The lockbox key sticks in cold weather')
            ->assertJsonPath('notes.0.location', 'Port side')
            ->assertJsonPath('notes.0.reporter_name', $note->reporter_name)
            ->assertJsonPath('notes.0.inspection_reference', $inspection->inspection_reference)
            ->assertJsonPath('notes.0.photo_url', null);
    }

    public function test_a_new_issue_creates_a_defect_a_reported_event_and_an_observation(): void
    {
        $description = 'Hinge is cracked and the door will not latch';
        $concern = $this->concern('issue', $description, 'Port aft locker');

        $this->submit($this->payload([$concern]))->assertCreated();

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame($this->boat->id, $defect->apparatus_id);
        $this->assertSame('Port aft locker', $defect->compartment);
        $this->assertSame($description, $defect->item);
        $this->assertSame($description, $defect->notes);
        $this->assertSame('damaged', $defect->issue_type);
        $this->assertSame('reported', $defect->action_status);
        $this->assertSame('routine', $defect->severity);
        $this->assertSame('member-concerns', $defect->compartment_key);
        $this->assertSame('concern-'.$concern['client_id'], $defect->item_key);
        $this->assertSame($this->member->id, $defect->first_reported_by_user_id);

        $event = ApparatusDefectEvent::query()->sole();
        $this->assertSame('reported', $event->event_type);
        $this->assertSame($this->member->id, $event->actor_user_id);
        $observation = ApparatusDefectObservation::query()->sole();
        $this->assertSame('first_reported', $observation->observation);
        $this->assertSame($description, $observation->notes);
        $this->assertDatabaseHas('apparatus_defect_participants', ['apparatus_defect_id' => $defect->id, 'user_id' => $this->member->id, 'enrollment_source' => 'reported']);
        $this->assertSame($defect->id, ApparatusInspectionConcern::query()->sole()->apparatus_defect_id);
        // Routine damage is a fact on record, not a hold.
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame('accepted', ApparatusInspection::query()->sole()->processing_status);

        $this->getJson("/api/public/apparatuses/{$this->boat->id}/findings")->assertOk()
            ->assertJsonPath('open.0.id', $defect->id)
            ->assertJsonPath('open.0.compartment', 'Port aft locker')
            ->assertJsonPath('open.0.description', $description)
            ->assertJsonPath('open.0.reporter_name', $this->member->display_name ?: $this->member->name)
            ->assertJsonPath('open.0.action_status', 'reported')
            ->assertJsonPath('notes', []);
    }

    public function test_an_existing_issue_gets_one_observation_and_one_event_and_keeps_its_original_report(): void
    {
        $original = User::factory()->create(['name' => 'First Reporter']);
        $defect = $this->openDefect($this->boat, ['notes' => 'Original report text', 'action_status' => 'acknowledged', 'first_reported_by_user_id' => $original->id]);
        $concern = $this->concern('issue', 'Still cracked, and worse today', null, $defect->id);

        $this->submit($this->payload([$concern]))->assertCreated();

        $this->assertDatabaseCount('apparatus_defects', 1);
        $fresh = $defect->fresh();
        $this->assertSame('Original report text', $fresh->notes);
        $this->assertSame('acknowledged', $fresh->action_status);
        $this->assertSame($original->id, $fresh->first_reported_by_user_id);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame($inspection->id, $fresh->last_observed_inspection_id);
        $event = ApparatusDefectEvent::query()->sole();
        $this->assertSame('observed_again', $event->event_type);
        $this->assertSame($this->member->id, $event->actor_user_id);
        $observation = ApparatusDefectObservation::query()->sole();
        $this->assertSame('confirmed_again', $observation->observation);
        $this->assertSame('Still cracked, and worse today', $observation->notes);
        $this->assertSame($defect->id, ApparatusInspectionConcern::query()->sole()->apparatus_defect_id);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);

        $timeline = $this->getJson("/api/public/apparatus-issues/{$defect->id}/history")->assertOk();
        $this->assertSame('Still cracked, and worse today', collect($timeline->json('timeline'))->firstWhere('type', 'observed_again')['text']);
        $timeline->assertJsonPath('issue.description', 'Original report text');
    }

    public function test_a_closed_or_foreign_issue_id_becomes_a_new_issue_and_touches_nothing(): void
    {
        $closed = $this->openDefect($this->boat, ['compartment' => 'Deck', 'item' => 'Old cleat', 'action_status' => 'resolved']);
        $other = Apparatus::query()->create(['name' => 'Engine 3', 'unit_id' => 'E3', 'designation' => 'E3', 'type' => 'Engine', 'vehicle_number' => 'TEST-103', 'status' => 'In Service']);
        $foreign = $this->openDefect($other, ['compartment' => 'Cab', 'item' => 'Radio']);

        $this->submit($this->payload([
            $this->concern('issue', 'Cleat is loose again', 'Foredeck', $closed->id),
            $this->concern('issue', 'Rail is bent', 'Aft deck', $foreign->id),
        ]))->assertCreated();

        $this->assertSame(4, ApparatusDefect::query()->count());
        $created = ApparatusDefect::query()->whereNotIn('id', [$closed->id, $foreign->id])->orderBy('id')->get();
        $this->assertSame(['Cleat is loose again', 'Rail is bent'], $created->pluck('notes')->all());
        $this->assertSame([$this->boat->id, $this->boat->id], $created->pluck('apparatus_id')->all());
        $this->assertSame(['reported', 'reported'], ApparatusDefectEvent::query()->orderBy('id')->pluck('event_type')->all());
        $this->assertDatabaseMissing('apparatus_defect_events', ['apparatus_defect_id' => $closed->id]);
        $this->assertDatabaseMissing('apparatus_defect_events', ['apparatus_defect_id' => $foreign->id]);
        $this->assertSame(
            $created->pluck('id')->all(),
            ApparatusInspectionConcern::query()->orderBy('id')->pluck('apparatus_defect_id')->all(),
        );
        $this->assertTrue((bool) $closed->fresh()->resolved);
    }

    public function test_two_concerns_with_one_location_and_the_same_first_120_characters_merge_into_one_issue(): void
    {
        $prefix = str_repeat('Locker door hinge is cracked ', 5);
        $first = $this->concern('issue', $prefix.' on the left', 'Port aft locker');
        $second = $this->concern('issue', $prefix.' and the right too', 'Port aft locker');
        $this->assertSame(mb_substr($first['description'], 0, 120), mb_substr($second['description'], 0, 120));

        $this->submit($this->payload([$first, $second]))->assertCreated();

        $this->assertDatabaseCount('apparatus_defects', 1);
        $defectId = ApparatusDefect::query()->sole()->id;
        $this->assertSame([$defectId, $defectId], ApparatusInspectionConcern::query()->orderBy('id')->pluck('apparatus_defect_id')->all());
        $this->assertDatabaseCount('apparatus_defect_observations', 1);
    }

    public function test_a_photo_is_stored_like_a_defect_photo_never_as_base64_and_is_served_with_authorization(): void
    {
        $concern = $this->concern('issue', 'Cracked housing', 'Console', null, self::PIXEL);
        $note = $this->concern('note', 'Photo of the console', 'Console', null, self::PIXEL);

        $this->submit($this->payload([$concern, $note]))->assertCreated();

        $rows = ApparatusInspectionConcern::query()->orderBy('id')->get();
        foreach ($rows as $row) {
            $this->assertStringStartsWith('defects/', $row->photo_path);
            $this->assertStringNotContainsString('base64', json_encode($row->getAttributes(), JSON_THROW_ON_ERROR));
            Storage::disk('public')->assertExists($row->photo_path);
            $this->get("/api/public/inspection-concerns/{$row->id}/photo")->assertOk();
        }
        $defect = ApparatusDefect::query()->sole();
        $this->assertSame($rows[0]->photo_path, $defect->photo_path);
        $this->assertSame($rows[0]->photo_path, ApparatusDefectObservation::query()->sole()->photo_path);
        $this->getJson("/api/public/apparatuses/{$this->boat->id}/findings")->assertOk()
            ->assertJsonPath('notes.0.photo_url', "/api/public/inspection-concerns/{$rows[1]->id}/photo");
    }

    public function test_a_failed_submission_deletes_the_photos_it_stored(): void
    {
        $payload = $this->payload([$this->concern('issue', 'Cracked housing', 'Console', null, self::PIXEL)]);
        $payload['compartments'][0]['items'][0]['photo'] = 'data:image/png;base64,AAAA';

        $this->submit($payload)->assertUnprocessable();

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('apparatus_inspection_concerns', 0);
        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_a_retry_of_the_same_submission_creates_nothing_twice(): void
    {
        $payload = $this->payload([
            $this->concern('note', 'A note', null),
            $this->concern('issue', 'A new issue', 'Console', null, self::PIXEL),
        ]);

        $this->submit($payload)->assertCreated();
        $this->submit($payload)->assertOk();

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertDatabaseCount('apparatus_inspection_concerns', 2);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_defect_events', 1);
        $this->assertDatabaseCount('apparatus_defect_observations', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('defects'));

        $changed = $payload;
        $changed['concerns'][0]['description'] = 'A different note';
        $this->submit($changed)->assertStatus(409)->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');
        $this->assertDatabaseCount('apparatus_inspection_concerns', 2);
    }

    public function test_no_concerns_is_one_thing_whether_the_key_is_absent_null_or_empty(): void
    {
        $payload = $this->payload([]);
        unset($payload['concerns']);
        $this->submit($payload)->assertCreated();
        $hash = ApparatusInspection::query()->sole()->submission_payload_hash;

        $this->submit([...$payload, 'concerns' => []])->assertOk();
        $this->submit([...$payload, 'concerns' => null])->assertOk();

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertSame($hash, ApparatusInspection::query()->sole()->submission_payload_hash);
        $this->assertDatabaseCount('apparatus_inspection_concerns', 0);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidConcerns(): array
    {
        $ok = ['client_id' => '41414141-4141-4141-8141-414141414141', 'kind' => 'note', 'description' => 'Fine'];

        return [
            'not a uuid' => [[[...$ok, 'client_id' => 'nope']], 'concerns.0.client_id'],
            'missing client id' => [[['kind' => 'note', 'description' => 'Fine']], 'concerns.0.client_id'],
            'unknown kind' => [[[...$ok, 'kind' => 'complaint']], 'concerns.0.kind'],
            'empty description' => [[[...$ok, 'description' => '']], 'concerns.0.description'],
            'spaces only' => [[[...$ok, 'description' => "  \n\t "]], 'concerns.0.description'],
            'too long' => [[[...$ok, 'description' => str_repeat('a', 2001)]], 'concerns.0.description'],
            'location too long' => [[[...$ok, 'location' => str_repeat('a', 256)]], 'concerns.0.location'],
            'issue id not a number' => [[[...$ok, 'kind' => 'issue', 'existing_issue_id' => 'abc']], 'concerns.0.existing_issue_id'],
            'duplicate client id' => [[$ok, [...$ok, 'description' => 'Again']], 'concerns.1.client_id'],
            'not a list of objects' => [['text'], 'concerns.0'],
            'more than twenty' => [array_map(static fn (int $n): array => [...$ok, 'client_id' => sprintf('42424242-4242-4242-8242-%012d', $n)], range(1, 21)), 'concerns'],
        ];
    }

    /** @param  list<mixed>  $concerns */
    #[DataProvider('invalidConcerns')]
    public function test_a_malformed_concern_fails_validation_and_records_nothing(array $concerns, string $field): void
    {
        $this->submit($this->payload($concerns))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_inspection_concerns', 0);
    }

    public function test_a_photo_the_server_cannot_read_costs_the_photo_and_never_the_concern_or_the_checkout(): void
    {
        $this->submit($this->payload([$this->concern('issue', 'Cracked housing', null, null, 'data:image/png;base64,AAAA')]))
            ->assertCreated();

        $concern = ApparatusInspectionConcern::query()->sole();
        $this->assertSame('Cracked housing', $concern->description);
        $this->assertNull($concern->photo_path);
        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertNotNull($concern->apparatus_defect_id, 'the problem still became an issue');
    }

    public function test_concerns_need_a_processed_submission(): void
    {
        $payload = $this->payload([$this->concern('note', 'A note', null)]);
        unset($payload['processing_version']);

        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors('concerns');
        $this->assertDatabaseCount('apparatus_inspection_concerns', 0);
    }

    public function test_the_evidence_is_stored_on_the_physical_apparatus_the_checkout_settled_on(): void
    {
        Queue::fake();
        $assigned = Apparatus::query()->create(['name' => 'Engine 6', 'unit_id' => 'E6', 'designation' => 'E6', 'type' => 'Engine', 'vehicle_number' => 'TEST-106', 'status' => 'In Service']);
        $physical = Apparatus::query()->create(['name' => 'Engine 7', 'unit_id' => 'E7', 'designation' => 'E7', 'type' => 'Engine', 'vehicle_number' => 'TEST-107', 'status' => 'In Service']);
        $open = $this->openDefect($physical, ['compartment' => 'Cab', 'item' => 'Radio', 'notes' => 'Radio is dead']);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $assigned->id, 'actor_user_id' => $this->member->id, 'operator_name' => 'Concern Reporter', 'rank' => 'Firefighter',
            'review_status' => 'pending_review',
            'results' => [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'radio', 'name' => 'Radio', 'status' => 'Present']]]],
            'pending_effects' => ['defects' => [], 'concerns' => [
                ['client_id' => '51515151-5151-4151-8151-515151515151', 'kind' => 'note', 'description' => 'Seat is worn', 'location' => null, 'photo_path' => null, 'existing_issue_id' => null],
                ['client_id' => '52525252-5252-4252-8252-525252525252', 'kind' => 'issue', 'description' => 'Seat belt will not retract', 'location' => 'Cab', 'photo_path' => null, 'existing_issue_id' => null],
                ['client_id' => '53535353-5353-4353-8353-535353535353', 'kind' => 'issue', 'description' => 'Radio is still dead', 'location' => null, 'photo_path' => null, 'existing_issue_id' => $open->id],
            ]],
        ]);

        DB::transaction(fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $physical, null));

        $this->assertSame([$physical->id], ApparatusInspectionConcern::query()->pluck('apparatus_id')->unique()->values()->all());
        $this->assertSame([$physical->id], ApparatusDefect::query()->pluck('apparatus_id')->unique()->values()->all());
        $this->assertDatabaseCount('apparatus_defects', 2);
        $this->assertSame($physical->id, $inspection->fresh()->processed_apparatus_id);
        $this->assertNull($inspection->fresh()->pending_effects);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $open->id)->where('event_type', 'observed_again')->count());
    }

    public function test_new_issues_and_confirmations_flow_into_the_findings_result_reviewers_are_told_about(): void
    {
        $open = $this->openDefect($this->boat, ['compartment' => 'Console', 'item' => 'Screen', 'notes' => 'Screen flickers']);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->boat->id, 'actor_user_id' => $this->member->id, 'operator_name' => 'Concern Reporter', 'rank' => 'Firefighter', 'review_status' => 'pending_review',
        ]);
        $recorder = app(InspectionConcernRecorder::class);

        $result = DB::transaction(function () use ($recorder, $inspection, $open) {
            $batch = $recorder->begin($inspection, $this->boat, [
                ['client_id' => '61616161-6161-4161-8161-616161616161', 'kind' => 'issue', 'description' => 'Throttle sticks', 'location' => 'Helm'],
                ['client_id' => '62626262-6262-4262-8262-626262626262', 'kind' => 'issue', 'description' => 'Still flickering', 'existing_issue_id' => $open->id],
            ]);
            $findings = app(DefectContinuityService::class)->recordFindings($inspection, $this->boat, $batch->compartments, [], []);

            return $recorder->finish($batch, $inspection, $this->boat, $findings);
        });

        $created = ApparatusDefect::query()->where('item', 'Throttle sticks')->sole();
        $this->assertSame([(int) $created->id], $result->newDefectIds);
        $this->assertSame([(int) $open->id], $result->confirmedDefectIds);
        $this->assertSame([], $result->significantNewOrEscalatedDefectIds);
    }

    public function test_a_concern_row_is_append_only_except_for_linking_its_defect_once(): void
    {
        $this->submit($this->payload([$this->concern('note', 'A note', null)]))->assertCreated();
        $note = ApparatusInspectionConcern::query()->sole();

        $this->expectException(LogicException::class);
        $note->update(['description' => 'Rewritten']);
    }

    public function test_a_linked_concern_keeps_its_first_defect(): void
    {
        $this->submit($this->payload([$this->concern('issue', 'Cracked housing', 'Console')]))->assertCreated();
        $row = ApparatusInspectionConcern::query()->sole();
        $first = $row->apparatus_defect_id;
        $other = $this->openDefect($this->boat, ['compartment' => 'Deck', 'item' => 'Cleat']);

        $row->linkToDefect($other);

        $this->assertSame($first, $row->fresh()->apparatus_defect_id);
    }

    public function test_reviewers_see_the_concerns_with_an_authorized_photo_link(): void
    {
        $this->submit($this->payload([
            $this->concern('issue', 'Cracked housing', 'Console', null, self::PIXEL),
            $this->concern('note', 'Key sticks', null),
        ]))->assertCreated();
        $inspection = ApparatusInspection::query()->sole();

        $concerns = app(InspectionWorkspace::class)->build($inspection, $this->member)['concerns'];

        $this->assertSame(['Issue', 'Note'], array_column($concerns, 'kind'));
        $this->assertSame(['Cracked housing', 'Key sticks'], array_column($concerns, 'description'));
        $this->assertSame(['Console', null], array_column($concerns, 'location'));
        $this->assertSame([$this->member->display_name ?: $this->member->name, $this->member->display_name ?: $this->member->name], array_column($concerns, 'reporter'));
        $this->assertNull($concerns[1]['photo_key']);
        $path = ApparatusInspectionConcern::query()->orderBy('id')->firstOrFail()->photo_path;
        $this->assertSame($path, app(EmployeeInspectionHistoryService::class)->resolveImagePath($inspection, $concerns[0]['photo_key']));
        $this->assertStringNotContainsString($path, json_encode($concerns, JSON_THROW_ON_ERROR), 'The page never sees a storage path');
    }

    public function test_scheduled_duties_print_completed_and_not_completed_alongside_the_legacy_words(): void
    {
        $this->assertSame('Completed', ScheduledDutyStatus::label('Completed'));
        $this->assertSame('Not completed', ScheduledDutyStatus::label('Not Completed'));
        $this->assertSame('Completed (recorded as Present)', ScheduledDutyStatus::label('Present'));
        $this->assertSame('Not completed (recorded as Missing)', ScheduledDutyStatus::label('Missing'));
        $this->assertSame('Not completed (recorded as Damaged)', ScheduledDutyStatus::label('Damaged'));
        $this->assertSame('Reported', ScheduledDutyStatus::label(null));

        $html = Blade::render('<x-inspection-checklist-v2-evidence :evidence="$evidence" />', ['evidence' => ['scheduled_tasks' => [
            ['id' => 'a', 'name' => 'Fuel tank hold', 'status' => 'Not Completed', 'notes' => 'Lift out of service'],
            ['id' => 'b', 'name' => 'Bilge check', 'status' => 'Completed'],
            ['id' => 'c', 'name' => 'Old answer', 'status' => 'Present'],
        ]]]);

        $this->assertStringContainsString('Not completed', $html);
        $this->assertMatchesRegularExpression('/>\s*Completed\s*</', $html);
        $this->assertStringContainsString('Completed (recorded as Present)', $html);
        $this->assertStringNotContainsString('Not Completed', $html);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<mixed>  $concerns
     * @return array<string, mixed>
     */
    private function payload(array $concerns): array
    {
        $contract = $this->startInspectionSession();

        return [...$this->appSubmission($contract), 'concerns' => $concerns];
    }

    /** @param array<string, mixed> $payload */
    private function submit(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/public/apparatuses/{$this->boat->id}/inspections", $payload);
    }

    /** @return array<string, mixed> */
    private function concern(string $kind, string $description, ?string $location, ?int $existing = null, ?string $photo = null): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'client_id' => sprintf('71717171-7171-4171-8171-%012d', $sequence),
            'kind' => $kind,
            'description' => $description,
            'location' => $location,
            'photo' => $photo,
            'existing_issue_id' => $existing,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function openDefect(Apparatus $apparatus, array $attributes = []): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Port aft locker',
            'item' => 'Hinge',
            'status' => 'open',
            'issue_type' => 'damaged',
            'notes' => 'Seeded issue',
            'action_status' => 'reported',
            'first_reported_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function startInspectionSession(): array
    {
        $response = $this->postJson("/api/public/apparatuses/{$this->boat->id}/inspection-sessions")->assertCreated();
        $browserCookie = collect($response->headers->getCookies())
            ->first(static fn (\Symfony\Component\HttpFoundation\Cookie $cookie): bool => $cookie->getName() === 'daily_checkout_inspection_browser');
        if ($browserCookie instanceof \Symfony\Component\HttpFoundation\Cookie) {
            $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue());
        }

        return $response->json('inspection_session');
    }

    /**
     * What the Daily Checkout app really posts for a member who filled in only what is required.
     *
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function appSubmission(array $contract): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->boat);
        $this->assertIsArray($resolution['checklist']);

        return [
            'processing_version' => 1,
            'meter_baseline_token' => $this->getJson('/api/public/apparatuses')->json('0.meter_baseline_token'),
            'client_submission_id' => self::SUBMISSION,
            'checklist_version' => $contract['checklist_hash'],
            'operator_name' => 'Firefighter Example',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'FB6',
            'engine_hours' => null,
            'miles' => null,
            'compartments' => array_map(static fn (array $compartment): array => [
                'id' => $compartment['id'],
                'name' => $compartment['name'],
                'items' => array_map(static fn (array $item): array => [
                    'id' => $item['id'], 'name' => $item['name'], 'status' => 'Present', 'observed' => true, 'notes' => null,
                ], $compartment['items']),
            ], $resolution['checklist']['compartments']),
            'defects' => [],
            'field_values' => array_map(static fn (array $field): array => [
                'id' => $field['id'],
                'value' => match (true) {
                    $field['inputType'] === 'date' => $contract['duty_date'],
                    $field['id'] === 'fb6-shift' => 'A',
                    $field['inputType'] === 'checkbox' => false,
                    $field['inputType'] === 'number', $field['inputType'] === 'percentage' => null,
                    default => '',
                },
            ], $resolution['checklist']['fields']),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Completed', 'notes' => null, 'observed' => true], $contract['due_tasks']),
            'inspection_session_id' => $contract['id'],
            'inspection_session_token' => $contract['token'],
            'inspection_session_replay_key' => $contract['replay_key'],
            'officer_signature' => self::PIXEL,
        ];
    }

    private function makeFireBoat6(): Apparatus
    {
        $station = Station::query()->firstOrCreate(
            ['station_number' => 6],
            ['name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true],
        );

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'FB6',
            'name' => 'Fire Boat 6',
            'type' => 'Fire Boat',
            'class_description' => 'Marine',
            'vehicle_number' => null,
            'designation' => 'FB6',
            'slug' => 'fire-boat-6',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
    }
}
