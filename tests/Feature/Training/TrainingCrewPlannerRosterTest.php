<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use App\Services\Training\CrewPlanner\TrainingRosterDutySource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** The planner reads the emailed TeleStaff roster the Hub already holds. Every identity here is synthetic. */
final class TrainingCrewPlannerRosterTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private User $admin;

    private TrainingCourse $course;

    /** @var array<string, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['captain5.shift_rotation' => ['enabled' => false]]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::DATE.' 10:00:00', \App\Support\HubTimezone::NAME));
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse(self::DATE.' 10:00:00', \App\Support\HubTimezone::NAME));
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $this->course = TrainingCourse::query()->create(['name' => 'Synthetic course', 'classifications' => ['STC'],
            'track_attendance_matrix' => false, 'created_by' => $this->admin->id]);
        foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Firefighter', '90004' => 'Lieutenant', '90005' => 'Firefighter', '90006' => 'Firefighter'] as $number => $rank) {
            $this->employees[$number] = Employee::query()->create(['employee_id' => $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $id, string $code = 'SP', string $availability = RosterAssignment::AVAILABILITY_WORKING, array $extra = []): array
    {
        return ['employee_id' => $id, 'name' => 'Synthetic '.$id, 'code' => $code, 'availability' => $availability, 'rank' => 'Firefighter', ...$extra];
    }

    private function publishRoster(array $rows = [], string $date = self::DATE, array $options = []): int
    {
        $rows = $rows !== [] ? $rows : [$this->row('90001'), $this->row('90002'), $this->row('90003')];

        return (int) DailyRosterSnapshotFactory::publish($date, ['Station 9 / Engine 9' => $rows], ['shift' => 'B', ...$options])->getKey();
    }

    private function draft(mixed $component): array
    {
        return app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $component->get('draftId'));
    }

    public function test_the_planner_has_no_upload_and_the_old_route_is_gone(): void
    {
        $this->publishRoster();
        self::assertFalse(Route::has('training.crew-planner.upload'));
        Livewire::test(TrainingCrewPlanner::class)
            ->assertDontSeeHtml('type="file"')->assertDontSee('TeleStaff PDF')->assertDontSee('Upload')
            ->assertSee('TeleStaff roster');
        $this->post('/training/crew-planner/upload')->assertNotFound();
    }

    public function test_the_roster_loads_by_itself_with_source_shift_and_time(): void
    {
        $this->publishRoster();
        Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)
            ->assertSet('scheduleDate', self::DATE)
            ->assertSeeHtml('data-roster-source="verified"')->assertSee('B Shift')->assertSee('as of')
            ->assertSee('3 eligible')->assertSeeHtml('aria-label="Select Synthetic 90001"');
    }

    public function test_it_keeps_working_seats_and_partial_intervals_and_identifies_people_by_employee_id(): void
    {
        $this->publishRoster([
            $this->row('90001', extra: ['from' => '08:00', 'through' => '20:00']),
            // Same printed name, different ID: two people, never merged.
            ['employee_id' => '90002', 'name' => 'Synthetic 90001', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING, 'rank' => 'Firefighter'],
        ]);
        $duty = app(TrainingRosterDutySource::class)->forDate(self::DATE);
        self::assertSame(['90001', '90002'], array_map('strval', array_keys($duty->staff)));
        $context = $duty->staff['90001']['observed_unit_context'][0];
        self::assertSame('Station 9', $context['station']);
        self::assertSame('Engine 9', $context['apparatus']);
        $interval = $duty->staff['90001']['working_intervals'][0];
        self::assertSame(12, (int) round((CarbonImmutable::parse($interval['end_at'])->getTimestamp() - CarbonImmutable::parse($interval['start_at'])->getTimestamp()) / 3600));
    }

    public function test_leave_is_not_available_and_its_reason_never_reaches_the_plan(): void
    {
        $this->publishRoster([
            $this->row('90001'),
            $this->row('90002', 'SIC', RosterAssignment::AVAILABILITY_NOT_WORKING, ['category' => 'Sick']),
            $this->row('90003', 'VACA', RosterAssignment::AVAILABILITY_NOT_WORKING, ['category' => 'Vacation']),
        ]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $component->assertSee('1 eligible')->assertDontSeeHtml('aria-label="Select Synthetic 90002"')->assertDontSeeHtml('aria-label="Select Synthetic 90003"');
        $component->assertDontSee('Sick')->assertDontSee('Vacation')->assertDontSee('VACA')->assertDontSee('SIC');
        $component->call('toggleMember', $this->employees['90002']->id)->assertHasErrors('selection');
        $json = json_encode($this->draft($component), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SIC', $json);
        self::assertStringNotContainsString('Sick', $json);
    }

    public function test_an_unknown_code_is_held_for_verification_not_called_available(): void
    {
        $this->publishRoster([$this->row('90001'), $this->row('90002', 'ZZZ', RosterAssignment::AVAILABILITY_UNKNOWN)]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $component->assertSee('1 eligible')->assertSee('Duty needs verification')->assertDontSee('ZZZ');
    }

    public function test_overtime_outranks_an_aday_only_for_its_own_hours(): void
    {
        $this->publishRoster([
            $this->row('90001', 'ADONIS DAY', RosterAssignment::AVAILABILITY_NOT_WORKING, ['category' => 'A-Day']),
            $this->row('90001', '+OT', RosterAssignment::AVAILABILITY_WORKING, ['from' => '08:00', 'through' => '12:00']),
        ]);
        $duty = app(TrainingRosterDutySource::class)->forDate(self::DATE);
        $intervals = $duty->staff['90001']['working_intervals'];
        self::assertCount(1, $intervals);
        self::assertSame('12:00', CarbonImmutable::parse($intervals[0]['end_at'])->setTimezone(\App\Support\HubTimezone::NAME)->format('H:i'));
    }

    public function test_a_session_needs_the_member_on_duty_for_all_of_it(): void
    {
        $this->publishRoster([
            $this->row('90001', extra: ['from' => '08:00', 'through' => '20:00']),
            $this->row('90002', extra: ['from' => '20:00', 'through' => '08:00']),
        ]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)
            ->set('slotEdits.0.start', '09:00')->set('slotEdits.0.end', '11:00')->assertHasNoErrors();
        $component->assertSee('1 eligible')->assertSeeHtml('aria-label="Select Synthetic 90001"')->assertDontSeeHtml('aria-label="Select Synthetic 90002"');
    }

    public function test_a_duty_date_with_no_roster_is_provisional_and_never_borrows_another_days(): void
    {
        $this->publishRoster();
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->set('scheduleDate', '2026-10-30');
        $component->assertHasNoErrors()->assertSeeHtml('data-roster-source="unavailable"')
            ->assertSee('has not published a roster')->assertSee('Duty not confirmed')->assertSee('provisional');
        $component->assertDontSee('On duty 08:00');
        $component->call('toggleMember', $this->employees['90004']->id)->assertHasNoErrors();
        self::assertArrayHasKey($this->employees['90004']->id, $this->draft($component)['selections']);
    }

    public function test_todays_roster_late_says_so_instead_of_using_yesterdays(): void
    {
        $this->publishRoster(date: '2026-10-20');
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $component->assertSeeHtml('data-roster-source="unavailable"')->assertDontSee('On duty 08:00');
    }

    public function test_another_verified_date_can_be_chosen_and_session_times_follow(): void
    {
        $this->publishRoster();
        $this->publishRoster([$this->row('90004')], '2026-10-22');
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)
            ->set('slotEdits.0.start', '09:00')->set('slotEdits.0.end', '11:00');
        $component->set('scheduleDate', '2026-10-22')->assertHasNoErrors()->assertSee('1 eligible')->assertSeeHtml('aria-label="Select Synthetic 90004"');
        self::assertSame('2026-10-22T09:00:00-04:00', $this->draft($component)['slots'][0]['start_at']);
    }

    public function test_an_impossible_date_is_refused_and_the_plan_keeps_its_date(): void
    {
        $this->publishRoster();
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $component->set('scheduleDate', 'not-a-date')->assertHasErrors('scheduleDate')->assertSet('scheduleDate', self::DATE);
        $component->set('scheduleDate', '2031-01-01')->assertHasErrors('scheduleDate');
    }

    public function test_a_newer_roster_keeps_the_list_and_only_interrupts_when_someone_on_it_changed(): void
    {
        $this->publishRoster([$this->row('90001'), $this->row('90002')]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $component->call('toggleMember', $this->employees['90001']->id);

        // Someone not on the plan changes: adopted quietly.
        $quiet = $this->publishRoster([$this->row('90001'), $this->row('90002', 'SIC', RosterAssignment::AVAILABILITY_NOT_WORKING)]);
        $component->call('refreshRoster')->assertDontSeeHtml('data-roster-changed');
        self::assertSame($quiet, $this->draft($component)['metadata']['acknowledged_snapshot_id']);

        // Someone on the plan changes: the list stays, and sending or exporting waits for a look.
        $this->publishRoster([$this->row('90001', 'SIC', RosterAssignment::AVAILABILITY_NOT_WORKING), $this->row('90002')]);
        $component->call('refreshRoster')->assertSeeHtml('data-roster-changed')->assertSee('Synthetic 90001');
        self::assertArrayHasKey($this->employees['90001']->id, $this->draft($component)['selections']);
        $component->set('confirmReview', true)->call('download', 'xlsx')->assertHasErrors('plan');
        $component->call('acknowledgeRoster')->assertDontSeeHtml('data-roster-changed')->assertSee('Needs verification');
    }

    public function test_a_shift_that_disagrees_with_the_calendar_confirms_nobody(): void
    {
        config(['captain5.shift_rotation' => ['enabled' => true, 'order' => ['A', 'B', 'C'], 'anchor_shift' => 'A', 'anchor_date' => self::DATE]]);
        $this->publishRoster(options: ['shift' => 'B']);
        self::assertNotNull(app(TrainingRosterDutySource::class)->forDate(self::DATE)->warning);
        Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)
            ->assertSee('0 eligible')->assertSee('does not match the department calendar');
    }

    public function test_a_future_date_shows_the_rotation_as_scheduled_not_as_fact(): void
    {
        config(['captain5.shift_rotation' => ['enabled' => true, 'order' => ['A', 'B', 'C'], 'anchor_shift' => 'A', 'anchor_date' => self::DATE]]);
        Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->set('scheduleDate', '2026-10-22')
            ->assertHasNoErrors()->assertSee('B Shift (scheduled)')->assertSeeHtml('data-roster-source="unavailable"');
    }
}
