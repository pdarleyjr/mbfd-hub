<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingPlannerAttendanceLookup;
use App\Services\Training\CrewPlanner\TrainingPlannerCandidateService;
use App\Services\Training\TrainingAttendanceMatrixService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingCrewPlannerProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TrainingCourse $course;

    private CarbonImmutable $cutoff;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->cutoff = CarbonImmutable::parse('2026-10-21T18:00:00Z');
        CarbonImmutable::setTestNow($this->cutoff);
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->course = TrainingCourse::query()->create(['name' => 'Synthetic crew planning course', 'classifications' => ['STC'],
            'track_attendance_matrix' => false, 'created_by' => $this->admin->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fresh_course_without_modules_sessions_or_matrix_is_read_only_and_identity_is_exact(): void
    {
        $employee = $this->employee('00123');
        $inactive = $this->employee('INACTIVE');
        $inactive->update(['roster_status' => 'retired']);
        $before = $this->domainSnapshot();
        // Database itself rejects accidental writes, including model events or implicit upserts.
        DB::statement('PRAGMA query_only = ON');
        try {
            $result = $this->candidates(['00123' => $this->duty('00123'), '123' => $this->duty('123'), 'INACTIVE' => $this->duty('INACTIVE')]);
            self::assertSame([$employee->id], array_keys($result['rows']));
            self::assertSame('NO_PRIOR_ATTENDANCE', $result['rows'][$employee->id]['attendance_status']);
            self::assertSame(['morning', 'afternoon'], $result['rows'][$employee->id]['eligible_slots']);
            self::assertCount(2, $result['review']);
            self::assertSame([], $result['session_suggestions']);
            self::assertSame($before, $this->domainSnapshot());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    public function test_course_and_module_never_attended_scopes_and_cutoff_do_not_cross(): void
    {
        $employee = $this->employee('SCOPE');
        $first = $this->module('Module 1');
        $second = $this->module('Module 2');
        $this->digital($employee, $this->trainingSession($first, 'submitted'));
        $this->digital($employee, $this->trainingSession($second, 'submitted', ['held_on' => '2026-10-22', 'starts_at' => '2026-10-22T13:00:00Z']));
        self::assertSame('VERIFIED_ATTENDED', $this->lookup($first->id)['rows'][$employee->id]['attendance_status']);
        self::assertSame('NO_PRIOR_ATTENDANCE', $this->lookup($second->id)['rows'][$employee->id]['attendance_status']);
        self::assertSame('VERIFIED_ATTENDED', $this->lookup()['rows'][$employee->id]['attendance_status']);
        $later = $this->employee('LATER');
        $this->digital($later, $this->trainingSession($first, 'submitted'), ['signed_at' => '2026-10-21T19:00:00Z']);
        self::assertSame('NO_PRIOR_ATTENDANCE', $this->lookup($first->id)['rows'][$later->id]['attendance_status']);
        $this->digital($later, $this->trainingSession(null, 'submitted'));
        self::assertSame('IDENTITY_OR_EVIDENCE_REVIEW', $this->lookup($second->id)['rows'][$later->id]['attendance_status']);
        self::assertSame('VERIFIED_ATTENDED', $this->lookup()['rows'][$later->id]['attendance_status']);
    }

    public function test_digital_paper_and_archived_proof_have_matrix_parity_and_no_private_material(): void
    {
        $module = $this->module('Evidence');
        $this->course->update(['track_attendance_matrix' => true]);
        $expected = [];
        foreach (['submitted' => 'VERIFIED_ATTENDED', 'certified' => 'VERIFIED_ATTENDED', 'closed' => 'UNVERIFIED_OR_PROVISIONAL',
            'open' => 'UNVERIFIED_OR_PROVISIONAL'] as $state => $status) {
            $employee = $this->employee(strtoupper($state));
            $this->digital($employee, $this->trainingSession($module, $state));
            $expected[$employee->id] = $status;
        }
        foreach (['approved' => 'VERIFIED_ATTENDED', 'pending' => 'IDENTITY_OR_EVIDENCE_REVIEW', 'excluded' => 'IDENTITY_OR_EVIDENCE_REVIEW',
            'voided' => 'IDENTITY_OR_EVIDENCE_REVIEW'] as $state => $status) {
            $employee = $this->employee('PAPER_'.$state);
            $this->paper($employee, $this->trainingSession($module, 'paper'), ['review_status' => $state]);
            $expected[$employee->id] = $status;
        }
        $archiveEmployee = $this->employee('ARCHIVED');
        $archived = $this->trainingSession($module, 'archived');
        $attendance = $this->digital($archiveEmployee, $archived);
        $this->archiveVersion($archived, $attendance, true);
        $expected[$archiveEmployee->id] = 'VERIFIED_ATTENDED';
        $closedArchiveEmployee = $this->employee('CLOSED_ARCHIVE');
        $closedArchive = $this->trainingSession($module, 'archived');
        $closedAttendance = $this->digital($closedArchiveEmployee, $closedArchive);
        $this->archiveVersion($closedArchive, $closedAttendance, false);
        $expected[$closedArchiveEmployee->id] = 'UNVERIFIED_OR_PROVISIONAL';
        $rows = $this->lookup($module->id)['rows'];
        $matrix = app(TrainingAttendanceMatrixService::class)->snapshot($this->course, ['module_id' => $module->id], '2026-10-21');
        foreach ($expected as $id => $status) {
            self::assertSame($status, $rows[$id]['attendance_status']);
            $matrixRow = collect($matrix['rows'])->firstWhere('employee_profile_id', $id);
            self::assertSame($status === 'VERIFIED_ATTENDED', $matrixRow['module_cells'][$module->id]['covered']);
        }
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE_TRANSCRIPTION', $encoded);
        self::assertStringNotContainsString('private/source', $encoded);
        self::assertStringNotContainsString('signature', $encoded);
    }

    public function test_invalid_voided_unreviewed_and_unassigned_evidence_never_becomes_eligible(): void
    {
        $module = $this->module('Selected');
        $staffing = [];
        foreach (['invalid', 'voided', 'late_signature', 'paper_no_reviewer', 'paper_bad_page', 'unassigned'] as $case) {
            $employee = $this->employee($case);
            $staffing[$case] = $this->duty($case);
            if (str_starts_with($case, 'paper_')) {
                $this->paper($employee, $this->trainingSession($module, 'paper'), $case === 'paper_no_reviewer' ? ['reviewed_by' => null] : ['source_page' => 3]);
            } else {
                $this->digital($employee, $this->trainingSession($case === 'unassigned' ? null : $module, 'submitted'), match ($case) {
                    'invalid' => ['signature' => []], 'voided' => ['voided_at' => $this->cutoff],
                    'late_signature' => ['signature_signed_at' => $this->cutoff->addHour()], default => [],
                });
            }
        }
        $result = $this->candidates($staffing, [], $module->id);
        foreach ($result['rows'] as $row) {
            self::assertSame('IDENTITY_OR_EVIDENCE_REVIEW', $row['attendance_status']);
            self::assertSame([], $row['eligible_slots']);
        }
        self::assertSame(0, $result['totals']['eligible']);
    }

    public function test_audience_is_authoritative_and_missing_bid_does_not_invent_a_shift(): void
    {
        $firefighter = $this->employee('FF');
        $captain = $this->employee('CAP', 'Captain');
        $officers = $this->module('Officers', ['audience' => ['ranks' => ['Captain']]]);
        $shift = $this->module('B Shift', ['audience' => ['shifts' => ['B Shift']]]);
        self::assertSame('NOT_APPLICABLE', $this->lookup($officers->id)['rows'][$firefighter->id]['attendance_status']);
        self::assertSame('NO_PRIOR_ATTENDANCE', $this->lookup($officers->id)['rows'][$captain->id]['attendance_status']);
        self::assertSame('IDENTITY_OR_EVIDENCE_REVIEW', $this->lookup($shift->id)['rows'][$firefighter->id]['attendance_status']);
        self::assertNull($this->lookup($shift->id)['rows'][$firefighter->id]['shift']);
    }

    public function test_published_de_and_shift_filters_remain_separate_from_actual_day_assignment(): void
    {
        $bidDe = $this->employee('DE');
        $ordinary = $this->employee('ORDINARY');
        $unknown = $this->employee('NO_BID');
        $this->assignment($bidDe, ['position_label' => 'Firefighter DE #2']);
        $this->assignment($ordinary);
        $staffing = ['DE' => $this->duty('DE'), 'ORDINARY' => $this->duty('ORDINARY'), 'NO_BID' => $this->duty('NO_BID')];
        $staffing['ORDINARY']['observed_unit_context'][0]['seat_rank'] = 'Firefighter DE';
        self::assertSame([$bidDe->id], array_keys($this->candidates($staffing, ['de_bid' => true, 'day_station' => 'Station 9'])['rows']));
        self::assertSame([$ordinary->id], array_keys($this->candidates($staffing, ['day_seat_rank' => 'Firefighter DE'])['rows']));
        self::assertSame([$ordinary->id], array_keys($this->candidates($staffing, ['de_bid' => false])['rows']));
        self::assertSame([$bidDe->id, $ordinary->id], array_keys($this->candidates($staffing, ['shift' => 'C Shift'])['rows']));
        self::assertFalse($this->candidates($staffing)['rows'][$unknown->id]['assignment_verified']);
        $staffing['DE']['needs_review'] = ['Exchange approval not confirmed.'];
        $staffing['DE']['eligible_slots'] = [];
        self::assertSame([], $this->candidates($staffing)['rows'][$bidDe->id]['eligible_slots']);
        self::assertNotEmpty($this->candidates($staffing)['rows'][$bidDe->id]['needs_review']);
    }

    public function test_analysis_time_and_roster_date_are_independent_and_suggestions_do_not_invent_end_time(): void
    {
        $employee = $this->employee('HISTORY');
        $this->assignment($employee);
        $module = $this->module('Module');
        $this->trainingSession($module, 'draft', ['held_on' => '2026-10-20', 'starts_at' => '2026-10-20T13:00:00Z']);
        $row = $this->lookup($module->id, '2026-09-30')['rows'][$employee->id];
        self::assertNull($row['shift']);
        self::assertSame('C Shift', $this->lookup($module->id)['rows'][$employee->id]['shift']);
        $result = $this->lookup($module->id, '2026-10-20');
        self::assertCount(1, $result['session_suggestions']);
        self::assertNull($result['session_suggestions'][0]['end_at']);
        self::assertSame('NO_PRIOR_ATTENDANCE', $result['rows'][$employee->id]['attendance_status']);
    }

    public function test_split_actual_day_contexts_are_distinct_from_bid_and_selected_slot_filters(): void
    {
        $employee = $this->employee('SPLIT');
        $this->assignment($employee, ['unit_label' => 'Engine 1', 'bid_selection_label' => 'Engine 1']);
        $duty = $this->duty('SPLIT');
        $duty['observed_unit_context'] = [
            ['station' => 'Station 9', 'apparatus' => 'Engine 9', 'seat_rank' => 'Firefighter',
                'start_at' => '2026-10-21T08:00:00-04:00', 'end_at' => '2026-10-21T12:00:00-04:00', 'source_page' => 1],
            ['station' => 'Station 8', 'apparatus' => 'Rescue 8', 'seat_rank' => 'Firefighter DE',
                'start_at' => '2026-10-21T12:00:00-04:00', 'end_at' => '2026-10-21T20:00:00-04:00', 'source_page' => 2],
        ];
        $staffing = ['SPLIT' => $duty];
        $result = $this->candidates($staffing);
        self::assertSame('Engine 1', $result['rows'][$employee->id]['assignment']);
        self::assertSame($duty['observed_unit_context'], $result['rows'][$employee->id]['observed_unit_context']);
        self::assertFalse($result['rows'][$employee->id]['is_de_bid']);
        self::assertSame([$employee->id], array_keys($this->candidates($staffing, ['published_assignment' => 'Engine 1'])['rows']));
        self::assertSame([], $this->candidates($staffing, ['published_assignment' => 'Rescue 8'])['rows']);
        self::assertSame([], $this->candidates($staffing, ['day_station' => 'Station 9', 'day_apparatus' => 'Rescue 8'])['rows']);
        $afternoon = ['slot_start_at' => '2026-10-21T13:00:00-04:00', 'slot_end_at' => '2026-10-21T16:00:00-04:00'];
        self::assertSame([], $this->candidates($staffing, $afternoon + ['day_apparatus' => 'Engine 9'])['rows']);
        self::assertSame([$employee->id], array_keys($this->candidates($staffing, $afternoon + ['day_station' => 'Station 8', 'day_apparatus' => 'Rescue 8'])['rows']));
    }

    public function test_review_outside_a_valid_slot_does_not_block_that_slot(): void
    {
        $employee = $this->employee('DAY_WORK');
        $duty = $this->duty('DAY_WORK');
        $duty['needs_review'] = ['An overnight interval requires verification.'];
        $duty['review_intervals'] = [['start_at' => '2026-10-21T20:00:00-04:00', 'end_at' => '2026-10-22T08:00:00-04:00']];
        $duty['global_review_reasons'] = [];
        $row = $this->candidates(['DAY_WORK' => $duty])['rows'][$employee->id];
        self::assertSame(['morning', 'afternoon'], $row['eligible_slots']);
        self::assertSame($duty['review_intervals'], $row['review_intervals']);
        self::assertNotEmpty($row['needs_review']);
    }

    public function test_viewers_and_revoked_administrators_are_denied_and_foreign_module_rejected(): void
    {
        $viewer = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $viewer->assignRole(Role::findOrCreate('training_viewer', 'web'));
        foreach ([$viewer, $this->admin] as $actor) {
            if ($actor === $this->admin) {
                $actor->removeRole('training_admin');
            }
            try {
                app(TrainingPlannerAttendanceLookup::class)->lookup($actor, $this->course, null, $this->cutoff);
                self::fail('Planner reads require training-management authorization.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        $this->admin->assignRole('training_admin');
        $other = TrainingCourse::query()->create(['name' => 'Other synthetic course', 'classifications' => ['STC'], 'created_by' => $this->admin->id]);
        $foreign = $this->module('Foreign', ['training_course_id' => $other->id]);
        $this->expectException(ValidationException::class);
        $this->lookup($foreign->id);
    }

    private function lookup(?int $moduleId = null, ?string $personnelAsOf = null): array
    {
        return app(TrainingPlannerAttendanceLookup::class)->lookup($this->admin, $this->course, $moduleId, $this->cutoff, $personnelAsOf);
    }

    private function candidates(array $staffing, array $filters = [], ?int $moduleId = null): array
    {
        return app(TrainingPlannerCandidateService::class)->candidates($this->admin, $this->course, $moduleId, $this->cutoff, $staffing, $filters);
    }

    private function duty(string $employeeId): array
    {
        return ['raw_employee_id' => $employeeId, 'raw_name' => 'Synthetic '.$employeeId,
            'working_intervals' => [['start_at' => '2026-10-21T08:00:00-04:00', 'end_at' => '2026-10-21T20:00:00-04:00']],
            'eligible_slots' => ['morning', 'afternoon'], 'unavailable_slots' => [], 'partial_slots' => [], 'needs_review' => [],
            'observed_unit_context' => [['station' => 'Station 9', 'apparatus' => 'Engine 9', 'seat_rank' => 'Firefighter',
                'start_at' => '2026-10-21T08:00:00-04:00', 'end_at' => '2026-10-21T20:00:00-04:00', 'source_page' => 1]]];
    }

    private function employee(string $number, string $rank = 'Firefighter'): Employee
    {
        return Employee::query()->create(['employee_id' => $number, 'name' => 'Synthetic '.$number, 'rank' => $rank,
            'roster_status' => 'active', 'password' => 'not-used-for-authentication']);
    }

    private function module(string $name, array $overrides = []): TrainingModule
    {
        return TrainingModule::query()->create(array_replace(['training_course_id' => $this->course->id, 'name' => $name,
            'sort_order' => 0, 'is_required' => true, 'created_by' => $this->admin->id], $overrides));
    }

    private function trainingSession(?TrainingModule $module, string $status, array $overrides = []): TrainingSession
    {
        return TrainingSession::query()->create(array_replace(['training_course_id' => $this->course->id, 'training_module_id' => $module?->id,
            'public_id' => (string) Str::ulid(), 'course_name' => $this->course->name, 'classifications' => ['STC'], 'held_on' => '2026-10-20',
            'status' => $status, 'attendance_source' => $status === 'paper' ? 'paper' : 'digital', 'revision' => 1, 'created_by' => $this->admin->id], $overrides));
    }

    private function signature(): array
    {
        return ['disk' => 'local', 'path' => 'private/source.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)];
    }

    private function digital(Employee $employee, TrainingSession $session, array $overrides = []): TrainingAttendance
    {
        return TrainingAttendance::query()->create(array_replace(['training_session_id' => $session->id, 'employee_profile_id' => $employee->id,
            'snapshot' => ['rank' => 'Firefighter'], 'signature' => $this->signature(), 'signed_at' => '2026-10-20T14:00:00Z',
            'client_submission_id' => (string) Str::uuid(), 'request_hash' => str_repeat('e', 64)], $overrides));
    }

    private function paper(Employee $employee, TrainingSession $session, array $overrides = []): TrainingPaperAttendance
    {
        $source = TrainingPaperRosterImport::query()->create(['training_session_id' => $session->id, 'source_sha256' => hash('sha256', (string) $session->id),
            'archive_sha256' => str_repeat('b', 64), 'manifest_sha256' => str_repeat('c', 64), 'source_filename' => 'synthetic.pdf', 'disk' => 'local',
            'path' => 'private/source.pdf', 'page_count' => 2, 'importer_version' => 'synthetic-test', 'imported_by' => $this->admin->id, 'imported_at' => $this->cutoff]);

        return TrainingPaperAttendance::query()->create(array_replace(['training_paper_roster_import_id' => $source->id, 'training_session_id' => $session->id,
            'employee_profile_id' => $employee->id, 'source_page' => 1, 'source_row' => 'row-1', 'raw_transcription' => 'PRIVATE_TRANSCRIPTION',
            'evidence_present' => true, 'review_status' => 'approved', 'reviewed_by' => $this->admin->id, 'reviewed_at' => $this->cutoff], $overrides));
    }

    private function archiveVersion(TrainingSession $session, TrainingAttendance $attendance, bool $submitted): void
    {
        TrainingRosterVersion::query()->create(['training_session_id' => $session->id, 'revision' => $session->revision,
            'snapshot' => ['session' => ['id' => $session->id, 'revision' => $session->revision, 'status' => 'archived'],
                'submission' => $submitted ? ['submitted_at' => '2026-10-20T17:00:00Z', 'submitted_by' => $this->admin->id] : [],
                'instructor_signoff' => $submitted ? ['recorded_by' => $this->admin->id, 'lead_snapshot' => ['employee_profile_id' => $attendance->employee_profile_id],
                    'signed_at' => '2026-10-20T17:00:00Z', 'signature' => $this->signature()] : [],
                'attendance' => [['id' => $attendance->id, 'employee_profile_id' => $attendance->employee_profile_id, 'signature' => $this->signature()]]]]);
    }

    private function assignment(Employee $employee, array $overrides = []): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->forceCreate(array_replace(['employee_profile_id' => $employee->id, 'payload_version' => 2,
            'bid_year' => 2026, 'term_label' => '2026–2027', 'bid_session_id' => 'synthetic-planner', 'position_id' => 'C901', 'rank_label' => 'Firefighter',
            'shift_label' => 'C Shift', 'station_label' => 'Station 1', 'division_label' => 'Operations', 'unit_label' => 'Combat Float',
            'position_label' => 'Firefighter #2', 'bid_selection_label' => 'Combat Float', 'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award',
            'a_day_label' => 'Group 1', 'picked_at' => '2026-10-01T12:00:00Z', 'idempotency_key' => (string) Str::uuid(), 'is_forced' => false,
            'source_sequence' => 1, 'payload_hash' => str_repeat('a', 64), 'source_result_hash' => str_repeat('b', 64),
            'source_workbook_sha256' => str_repeat('c', 64), 'created_at' => '2026-10-01 12:00:00'], $overrides));
    }

    private function domainSnapshot(): array
    {
        $snapshot = [];
        foreach (['training_courses', 'training_modules', 'training_sessions', 'training_attendances', 'training_paper_roster_imports',
            'training_paper_attendances', 'training_paper_review_audits', 'training_roster_versions', 'training_roster_audits', 'employees', 'employee_bid_assignments'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
