<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingKioskGrant;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingAssignmentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Division chiefs, the Fire Chief, Deputy Chiefs and every other current member whose position does not
 * bid must be able to sign classroom rosters. The kiosk used to offer only people with a published bid.
 */
final class TrainingNonBidRosterEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const AS_OF = '2026-10-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config()->set('training-roster.bid_api_url', 'https://bid-api.example.test');
        config()->set('services.bid.reader_token', 'synthetic-reader-token');
    }

    public function test_division_chief_and_other_non_bid_members_are_offered_alongside_bid_members(): void
    {
        $fixture = $this->fixtureDepartment();
        $ids = collect(app(TrainingAssignmentProvider::class)->candidates(self::AS_OF))->pluck('employee_profile_id')->all();

        foreach (['firefighter', 'captain', 'divisionChief', 'fireChief', 'deputyChief'] as $key) {
            self::assertContains($fixture[$key]->id, $ids, $key.' must be offered for signing');
        }
    }

    public function test_non_bid_members_use_operational_shift_or_command_staff_and_never_claim_a_bid(): void
    {
        $fixture = $this->fixtureDepartment();
        $byId = collect(app(TrainingAssignmentProvider::class)->candidates(self::AS_OF))->keyBy('employee_profile_id');

        $chief = $byId[$fixture['divisionChief']->id];
        self::assertSame('Days', $chief['shift']);
        self::assertSame('Division Chief', $chief['rank']);
        self::assertNull($chief['term_label']);
        self::assertSame('non_bid_roster', $chief['assignment_source']);
        self::assertSame('hub_active_roster', $chief['assignment_basis']);
        self::assertArrayNotHasKey('source_result_hash', $chief);

        $fireChief = $byId[$fixture['fireChief']->id];
        self::assertSame(TrainingAssignmentProvider::STAFF_SHIFT_LABEL, $fireChief['shift']);
        self::assertSame('Fire Chief', $fireChief['rank']);

        $captain = $byId[$fixture['captain']->id];
        self::assertSame('A Shift', $captain['shift']);
        self::assertSame('published_bid_selection', $captain['assignment_basis']);
        self::assertSame('2026–2027', $captain['term_label']);
    }

    public function test_bridge_names_are_preferred_and_hub_profile_fills_only_what_the_bridge_lacks(): void
    {
        $fixture = $this->fixtureDepartment();
        $byId = collect(app(TrainingAssignmentProvider::class)->candidates(self::AS_OF))->keyBy('employee_profile_id');

        $bridgeNamed = $byId[$fixture['firefighter']->id];
        self::assertSame(['Frank', 'Fixture', 'bridge', 'bridge'], [$bridgeNamed['first_name'], $bridgeNamed['last_name'], $bridgeNamed['name_source'], $bridgeNamed['rank_source']]);

        $deputy = $byId[$fixture['deputyChief']->id];
        self::assertSame(['Dana', 'Alvarez', 'hub_profile_derived', 'hub_profile'], [$deputy['first_name'], $deputy['last_name'], $deputy['name_source'], $deputy['rank_source']]);
        self::assertSame('Deputy Chief', $deputy['rank']);

        // A published bid does not make a blank bridge name disappear either.
        $bidWithoutBridgeName = $byId[$fixture['bidBlankName']->id];
        self::assertSame(['Sam', 'Rivera', 'Lieutenant'], [$bidWithoutBridgeName['first_name'], $bidWithoutBridgeName['last_name'], $bidWithoutBridgeName['rank']]);
        self::assertSame('C Shift', $bidWithoutBridgeName['shift']);
    }

    public function test_active_hub_members_the_bridge_does_not_list_are_offered_and_reported(): void
    {
        $fixture = $this->fixtureDepartment();
        $unlisted = collect(app(TrainingAssignmentProvider::class)->candidates(self::AS_OF))->keyBy('employee_profile_id')[$fixture['unlisted']->id];

        self::assertSame('not_listed', $unlisted['source_employment_status']);
        self::assertSame('Pat', $unlisted['first_name']);
        self::assertSame('Quintero', $unlisted['last_name']);
    }

    public function test_inactive_retired_separated_departed_and_ineligible_people_stay_excluded_with_reasons(): void
    {
        $fixture = $this->fixtureDepartment();
        $result = app(TrainingAssignmentProvider::class)->evaluate(self::AS_OF);
        $offered = collect($result['candidates'])->pluck('employee_profile_id')->all();
        $reasons = collect($result['exclusions'])->pluck('reason', 'employee_profile_id');

        foreach (['retired' => 'bridge_status_retired', 'separated' => 'bridge_status_separated', 'inactive' => 'bridge_status_inactive',
            'departed' => 'hub_not_active', 'ineligible' => 'bridge_not_eligible'] as $key => $reason) {
            self::assertNotContains($fixture[$key]->id, $offered, $key.' must not sign');
            self::assertSame($reason, $reasons[$fixture[$key]->id]);
        }
        // A departed Hub profile the bridge does not list is never offered, and is not an "active but left out" exception.
        self::assertNotContains($fixture['departedUnlisted']->id, $offered);
        self::assertArrayNotHasKey($fixture['departedUnlisted']->id, $reasons->all());
    }

    public function test_each_person_is_offered_once_even_when_the_bridge_and_hub_ids_differ_by_boundary_whitespace(): void
    {
        $fixture = $this->fixtureDepartment();
        $candidates = collect(app(TrainingAssignmentProvider::class)->candidates(self::AS_OF));

        self::assertSame($candidates->count(), $candidates->pluck('employee_profile_id')->unique()->count());
        self::assertSame(1, $candidates->where('employee_profile_id', $fixture['padded']->id)->count());
        // Leading zeros are identity, not noise; the stored value is reported exactly as the Hub holds it.
        self::assertSame($fixture['padded']->employee_id, $candidates->firstWhere('employee_profile_id', $fixture['padded']->id)['employee_id'] ?? null);
        self::assertStringStartsWith('000112', (string) $fixture['padded']->employee_id);
    }

    public function test_an_unlisted_active_member_with_a_duplicated_or_missing_identity_is_reported_not_offered(): void
    {
        $this->fixtureDepartment();
        // The database keeps exact ids unique, but a pasted trailing space makes two rows one identity.
        $first = Employee::query()->create(['employee_id' => 'DUP-1', 'name' => 'Twin One', 'rank' => 'Captain', 'roster_status' => 'active']);
        $second = Employee::query()->create(['employee_id' => "DUP-1\u{00A0}", 'name' => 'Twin Two', 'rank' => 'Captain', 'roster_status' => 'active']);
        $blank = Employee::query()->create(['employee_id' => '', 'name' => 'No Identity', 'rank' => 'Captain', 'roster_status' => 'active']);

        $result = app(TrainingAssignmentProvider::class)->evaluate(self::AS_OF);
        $reasons = collect($result['exclusions'])->pluck('reason', 'employee_profile_id');

        self::assertSame('hub_identity_duplicate', $reasons[$first->id]);
        self::assertSame('hub_identity_duplicate', $reasons[$second->id]);
        self::assertSame('hub_identity_missing', $reasons[$blank->id]);
        self::assertEmpty(array_intersect([$first->id, $second->id, $blank->id], collect($result['candidates'])->pluck('employee_profile_id')->all()));
    }

    public function test_a_hub_identity_that_is_ambiguous_for_a_listed_member_still_fails_closed(): void
    {
        $fixture = $this->fixtureDepartment();
        Employee::query()->create(['employee_id' => $fixture['divisionChief']->employee_id.' ', 'name' => 'Clone', 'rank' => 'Captain', 'roster_status' => 'active']);

        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates(self::AS_OF);
    }

    public function test_a_missing_bid_publication_still_fails_closed_rather_than_guessing_every_shift(): void
    {
        $this->fixtureDepartment();
        EmployeeBidAssignment::query()->update(['superseded_at' => '2026-10-10 12:00:00']);

        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates(self::AS_OF);
    }

    public function test_name_splitting_handles_last_first_and_first_last_forms(): void
    {
        self::assertSame(['Dana', 'Alvarez'], TrainingAssignmentProvider::splitName('Alvarez, Dana'));
        self::assertSame(['Mary Ann', 'Smith'], TrainingAssignmentProvider::splitName('Mary Ann  Smith'));
        self::assertSame(['', 'Cher'], TrainingAssignmentProvider::splitName('Cher'));
        self::assertSame(['', ''], TrainingAssignmentProvider::splitName('   '));
    }

    public function test_kiosk_offers_signs_and_persists_a_division_chief_once(): void
    {
        $fixture = $this->fixtureDepartment(now(\App\Support\HubTimezone::NAME)->toDateString());
        $roster = $this->openRoster();
        $this->withSession(['training_grants.'.$roster->id => $this->grant($roster)->id]);
        $url = fn (string $suffix): string => 'https://rosters.mbfdhub.com/r/'.$roster->public_id.'/'.$suffix;

        $members = $this->getJson($url('candidates'))->assertOk()->assertDontSee('000103')->json('members');
        $chief = collect($members)->firstWhere('id', $fixture['divisionChief']->id);
        self::assertNotNull($chief, 'the division chief must appear in the kiosk list');
        self::assertSame('Division Chief', $chief['rank']);
        self::assertSame('0103', $chief['identifier_hint']);
        self::assertSame(count($members), collect($members)->pluck('id')->unique()->count());
        // The screen reads one shared term for its heading; non-bid members must not break it.
        self::assertSame(['2026–2027'], collect($members)->pluck('term_label')->unique()->values()->all());

        $payload = ['employee_profile_id' => $fixture['divisionChief']->id, 'signature' => $this->signature(), 'client_submission_id' => (string) Str::uuid()];
        $this->postJson($url('attendance'), $payload)->assertOk()->assertJsonPath('recorded', true);
        // A lost response is retried with the same receipt; it records nothing new.
        $this->postJson($url('attendance'), $payload)->assertOk();
        // The same person cannot sign again under a fresh receipt.
        $this->postJson($url('attendance'), array_replace($payload, ['client_submission_id' => (string) Str::uuid()]))->assertUnprocessable();

        $this->assertDatabaseCount('training_attendances', 1);
        $attendance = $roster->attendances()->firstOrFail();
        self::assertSame($fixture['divisionChief']->id, $attendance->employee_profile_id);
        self::assertSame('Division Chief', $attendance->snapshot['rank']);
        self::assertSame('Days', $attendance->snapshot['shift']);
        self::assertSame('non_bid_roster', $attendance->snapshot['assignment_source']);
        self::assertSame('000103', $attendance->snapshot['employee_id']);
        $this->getJson($url('state'))->assertOk()->assertJsonCount(1, 'attendance')->assertJsonPath('attendance.0.rank', 'Division Chief')->assertJsonPath('attendance.0.shift', 'Days');
    }

    public function test_kiosk_refuses_a_departed_or_retired_member_even_with_a_forged_profile_id(): void
    {
        $fixture = $this->fixtureDepartment(now(\App\Support\HubTimezone::NAME)->toDateString());
        $roster = $this->openRoster();
        $this->withSession(['training_grants.'.$roster->id => $this->grant($roster)->id]);
        $url = 'https://rosters.mbfdhub.com/r/'.$roster->public_id.'/attendance';

        foreach (['departed', 'retired', 'ineligible', 'departedUnlisted'] as $key) {
            $this->postJson($url, ['employee_profile_id' => $fixture[$key]->id, 'signature' => $this->signature(), 'client_submission_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('training_attendances', 0);
    }

    public function test_report_command_is_read_only_and_never_prints_names_or_full_ids(): void
    {
        $fixture = $this->fixtureDepartment();
        $employeesBefore = Employee::query()->orderBy('id')->get(['id', 'roster_status', 'name', 'rank'])->toArray();

        self::assertSame(0, Artisan::call('training:roster-eligibility', ['--as-of' => self::AS_OF, '--json' => true, '--details' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $report['signable_without_published_bid_by_rank']['Division Chief']);
        self::assertSame(1, $report['signable_without_published_bid_by_rank']['Fire Chief']);
        self::assertSame(1, $report['signable_not_listed_by_bridge']);
        self::assertSame(['hub_not_active' => 1], $report['bridge_members_excluded_by_reason']);
        self::assertSame(['bridge_not_eligible' => 1, 'bridge_status_inactive' => 1, 'bridge_status_retired' => 1, 'bridge_status_separated' => 1], $report['hub_active_who_cannot_sign_by_reason']);
        self::assertSame(4, $report['hub_active_who_cannot_sign']);
        foreach (['Alvarez', 'Quintero', 'Fixture', '000103', '000104'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
        self::assertSame($employeesBefore, Employee::query()->orderBy('id')->get(['id', 'roster_status', 'name', 'rank'])->toArray());
        self::assertNotNull($fixture['divisionChief']);
    }

    public function test_report_command_fails_visibly_when_the_bridge_is_unavailable(): void
    {
        $this->fixtureDepartment();
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['error' => 'bridge_disabled'], 503)]);

        self::assertSame(1, Artisan::call('training:roster-eligibility', ['--as-of' => self::AS_OF]));
        self::assertStringContainsString('could not be evaluated', Artisan::output());
    }

    /**
     * @return array<string, Employee>
     */
    private function fixtureDepartment(string $asOf = self::AS_OF): array
    {
        $make = fn (string $id, string $name, string $rank, string $status = 'active'): Employee => Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => $rank, 'roster_status' => $status]);
        $fixture = [
            'firefighter' => $make('000101', 'Frank Fixture', 'Firefighter'),
            'captain' => $make('000102', 'Cara Fixture', 'Captain'),
            'divisionChief' => $make('000103', 'Dale Fixture', 'Division Chief'),
            'fireChief' => $make('000104', 'Fiona Fixture', 'Fire Chief'),
            'deputyChief' => $make('000105', 'Alvarez, Dana', 'Deputy Chief'),
            'bidBlankName' => $make('000106', 'Sam Rivera', 'lieutenant'),
            'retired' => $make('000107', 'Rhett Retired', 'Captain'),
            'departed' => $make('000108', 'Dee Departed', 'Firefighter', 'departed'),
            'unlisted' => $make('000109', 'Pat Quintero', 'Firefighter'),
            'separated' => $make('000110', 'Sid Separated', 'Firefighter'),
            'inactive' => $make('000111', 'Ina Inactive', 'Firefighter'),
            'padded' => $make("000112\u{00A0}", 'Pad Person', 'Firefighter'),
            'ineligible' => $make('000113', 'Ivy Ineligible', 'Firefighter'),
            'departedUnlisted' => $make('000114', 'Una Unlisted', 'Firefighter', 'departed'),
        ];
        $this->publish($fixture['firefighter'], ['shift_label' => 'C Shift', 'idempotency_key' => 'ff']);
        $this->publish($fixture['captain'], ['shift_label' => 'A Shift', 'idempotency_key' => 'cpt']);
        $this->publish($fixture['bidBlankName'], ['shift_label' => 'C Shift', 'idempotency_key' => 'lt']);
        $this->publish($fixture['padded'], ['shift_label' => 'B Shift', 'idempotency_key' => 'pad']);

        $member = fn (string $id, ?string $first, ?string $last, ?string $rank, ?string $shift, string $status = 'active', bool $eligible = true): array => [
            'employee_id' => $id, 'first_name' => $first, 'last_name' => $last, 'rank' => $rank, 'shift' => $shift, 'assignment_id' => null,
            'assignment_source' => null, 'effective_from' => null, 'effective_to' => null, 'eligible' => $eligible, 'employment_status' => $status,
        ];
        Http::fake(['bid-api.example.test/*' => Http::response(['schema_version' => 1, 'as_of' => $asOf, 'generated_at' => now()->toIso8601String(),
            'source_updated_at' => 1, 'term_label' => null, 'members' => [
                $member('000101', 'Frank', 'Fixture', 'Firefighter', 'C Shift'),
                $member('000102', 'Cara', 'Fixture', 'Captain', 'A Shift'),
                $member('000103', 'Dale', 'Fixture', 'Division Chief', 'Days'),
                $member('000104', 'Fiona', 'Fixture', 'Fire Chief', null, 'unknown'),
                $member('000105', null, null, null, null),
                $member('000106', null, null, null, 'A Shift'),
                $member('000107', 'Rhett', 'Retired', 'Captain', null, 'retired'),
                $member('000108', 'Dee', 'Departed', 'Firefighter', null),
                $member('000110', 'Sid', 'Separated', 'Firefighter', null, 'separated'),
                $member('000111', 'Ina', 'Inactive', 'Firefighter', null, 'inactive'),
                $member('000112', 'Pad', 'Person', 'Firefighter', 'B Shift'),
                $member('000113', 'Ivy', 'Ineligible', 'Firefighter', null, 'active', false),
            ]])]);

        return $fixture;
    }

    /** @param  array<string, mixed>  $overrides */
    private function publish(Employee $employee, array $overrides = []): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->forceCreate(array_replace([
            'employee_profile_id' => $employee->getKey(), 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => '2026–2027', 'bid_session_id' => 'synthetic-official-session', 'position_id' => 'C001',
            'rank_label' => 'Captain', 'shift_label' => 'C Shift', 'station_label' => 'Station 1',
            'unit_label' => 'Engine', 'bid_selection_label' => 'Synthetic assignment', 'assignment_source' => 'bid_award',
            'a_day_label' => 'Group 1', 'picked_at' => '2026-10-01T12:00:00Z', 'idempotency_key' => 'synthetic-publication',
            'payload_hash' => str_repeat('b', 64), 'is_forced' => false, 'source_sequence' => 237,
            'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('c', 64),
            'created_at' => '2026-10-01 12:00:00',
        ], $overrides));
    }

    private function openRoster(): TrainingSession
    {
        $instructor = User::factory()->create(['account_status' => AccountStatus::Active]);
        $course = TrainingCourse::query()->create(['name' => 'Synthetic classroom test', 'classifications' => ['EMS Training'], 'created_by' => $instructor->id]);

        return TrainingSession::query()->create([
            'public_id' => (string) Str::ulid(), 'training_course_id' => $course->id, 'course_name' => $course->name,
            'classifications' => $course->classifications, 'held_on' => self::AS_OF, 'status' => 'open', 'revision' => 1, 'created_by' => $instructor->id,
        ]);
    }

    private function grant(TrainingSession $roster): TrainingKioskGrant
    {
        return TrainingKioskGrant::query()->create(['training_session_id' => $roster->id, 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addHour(), 'created_by' => $roster->created_by]);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(500, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 10, 20, 40);
        imagesetthickness($image, 5);
        for ($x = 30; $x < 450; $x += 30) {
            imageline($image, $x, 130, $x + 20, 40, $ink);
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
