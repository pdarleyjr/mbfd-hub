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
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingAttendanceMatrixService;
use App\Services\Training\TrainingMatrixExportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class TrainingAttendanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->course = TrainingCourse::query()->create(['name' => 'Synthetic matrix course', 'classifications' => ['STC'],
            'track_attendance_matrix' => true, 'created_by' => $this->admin->id]);
        Http::preventStrayRequests();
    }

    public function test_final_digital_and_reviewed_paper_cover_module_once_and_keep_every_session(): void
    {
        $employee = $this->employee('00001');
        $this->assignment($employee);
        $first = $this->module('Module 1');
        $second = $this->module('Module 2');
        $optional = $this->module('Optional', ['is_required' => false]);
        $submitted = $this->trainingSession($first, 'submitted');
        $repeat = $this->trainingSession($first, 'certified');
        $paper = $this->trainingSession($first, 'paper', ['attendance_source' => 'paper']);
        $provisional = $this->trainingSession($second, 'closed');
        $unassigned = $this->trainingSession(null, 'submitted');
        foreach ([$submitted, $repeat, $provisional, $unassigned, $this->trainingSession($optional, 'submitted')] as $session) {
            $this->digital($employee, $session);
        }
        $this->paper($employee, $paper);
        $voided = $this->digital($employee, $this->trainingSession($second, 'certified'));
        $voided->update(['voided_at' => now()]);
        $row = $this->snapshot()['rows'][0];
        self::assertSame(2, $row['required_count']);
        self::assertSame(1, $row['covered_count']);
        self::assertSame(50.0, $row['percent']);
        self::assertSame([$second->id], $row['missing_module_ids']);
        self::assertSame('digital_and_paper', $row['module_cells'][$first->id]['status']);
        self::assertSame('digital', $row['session_cells'][$submitted->id]['status']);
        self::assertSame('digital', $row['session_cells'][$repeat->id]['status']);
        self::assertSame('paper', $row['session_cells'][$paper->id]['status']);
        self::assertSame('provisional', $row['session_cells'][$provisional->id]['status']);
        self::assertTrue($row['has_provisional']);
        self::assertSame('digital', $row['session_cells'][$unassigned->id]['status']);
        self::assertCount(3, $row['module_cells'][$first->id]['session_ids']);
        self::assertNotEmpty(array_filter($this->snapshot()['exceptions'], fn ($exception): bool => $exception['type'] === 'unassigned_session'));
        Http::assertNothingSent();
    }

    public function test_review_unsigned_void_excluded_and_unmatched_paper_never_count_and_private_transcription_is_omitted(): void
    {
        $employee = $this->employee('REVIEW');
        $module = $this->module('Required');
        foreach ([['pending', true], ['approved', false], ['voided', true], ['excluded', true]] as [$status, $evidence]) {
            $this->paper($employee, $this->trainingSession($module, 'paper'), ['review_status' => $status, 'evidence_present' => $evidence]);
        }
        $this->paper(null, $this->trainingSession($module, 'paper'));
        $invalid = $this->digital($employee, $this->trainingSession($module, 'submitted'));
        $invalid->update(['signature' => []]);
        $data = $this->snapshot();
        self::assertSame(0, $data['rows'][0]['covered_count']);
        self::assertSame('review', $data['rows'][0]['module_cells'][$module->id]['status']);
        self::assertTrue($data['rows'][0]['has_review']);
        self::assertCount(7, $data['exceptions']);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE_TRANSCRIPTION', $json);
        self::assertStringNotContainsString('private/source', $json);
        self::assertStringNotContainsString('"signature":', $json);
        self::assertStringNotContainsString(str_repeat('d', 64), $json);
    }

    public function test_audience_applicability_and_optional_modules_control_denominators(): void
    {
        $known = $this->employee('KNOWN');
        $this->assignment($known);
        $unknown = $this->employee('UNKNOWN');
        $bShift = $this->module('B Shift', ['audience' => ['ranks' => ['Firefighter'], 'shifts' => ['B Shift']]]);
        $this->module('Officers', ['audience' => ['ranks' => ['Captain', 'Lieutenant']]]);
        $this->module('Optional', ['is_required' => false]);
        $rows = collect($this->snapshot()['rows'])->keyBy('employee_id');
        self::assertSame(1, $rows['KNOWN']['required_count']);
        self::assertSame(0, $rows['UNKNOWN']['required_count']);
        self::assertNull($rows['UNKNOWN']['percent']);
        self::assertSame('not_applicable', $rows['UNKNOWN']['status']);
        self::assertNull($rows['UNKNOWN']['module_cells'][$bShift->id]['applicable']);
        self::assertSame('review', $rows['UNKNOWN']['module_cells'][$bShift->id]['status']);
        self::assertTrue($rows['UNKNOWN']['has_review']);
        self::assertSame($unknown->id, $rows['UNKNOWN']['employee_profile_id']);
    }

    public function test_combined_filters_use_canonical_rank_true_de_bid_and_missing_selected_module(): void
    {
        $de = $this->employee('DE');
        $this->assignment($de, ['position_label' => 'Firefighter DE #2', 'assignment_type' => 'Floating']);
        $certificationOnly = $this->employee('ORDINARY');
        $this->assignment($certificationOnly, ['position_label' => 'Firefighter #2']);
        $retained = $this->employee('RETAINED');
        $this->assignment($retained, ['position_label' => 'Firefighter DE', 'assignment_source' => 'retained_nonbiddable']);
        $captain = $this->employee('CAPTAIN', 'Captain');
        $this->assignment($captain, ['rank_label' => 'Firefighter', 'unit_label' => 'Captain 5', 'bid_selection_label' => 'Captain 5', 'position_label' => 'Captain']);
        $lieutenant = $this->employee('LIEUTENANT', 'Lieutenant');
        $this->assignment($lieutenant, ['unit_label' => 'Engine 2', 'bid_selection_label' => 'Engine 2', 'position_label' => 'Lieutenant']);
        $first = $this->module('Module 1');
        $second = $this->module('Module 2');
        $this->digital($de, $this->trainingSession($first, 'submitted'));
        $data = $this->snapshot(['shift' => 'B Shift', 'cohort' => 'firefighters', 'de_bid' => true, 'floating' => true, 'missing_module_id' => $second->id]);
        self::assertSame(['DE'], array_column($data['rows'], 'employee_id'));
        self::assertSame(['CAPTAIN'], array_column($this->snapshot(['cohort' => 'officers', 'assignment' => 'rescue'])['rows'], 'employee_id'));
        self::assertSame(['LIEUTENANT'], array_column($this->snapshot(['cohort' => 'lieutenant', 'assignment' => 'combat'])['rows'], 'employee_id'));
        self::assertCount(2, $this->snapshot(['cohort' => 'officers'])['rows']);
        self::assertSame('all', $this->snapshot(['search' => 'DE', 'module_id' => $first->id])['rows'][0]['status']);
        self::assertSame('none', $this->snapshot(['search' => 'DE', 'module_id' => $second->id])['rows'][0]['status']);
    }

    public function test_latest_published_term_respects_new_york_as_of_boundary_and_invalid_provenance_is_visible(): void
    {
        $employee = $this->employee('TERM');
        $this->assignment($employee);
        $this->assignment($employee, ['bid_year' => 2027, 'term_label' => '2027–2028', 'shift_label' => 'C Shift', 'created_at' => '2026-10-21 04:00:00']);
        $this->module('Required');
        $row = $this->snapshot()['rows'][0];
        self::assertSame('2026–2027', $row['term_label']);
        self::assertSame('B Shift', $row['shift']);
        $row = $this->snapshot([], '2026-10-21')['rows'][0];
        self::assertSame('2027–2028', $row['term_label']);
        self::assertSame('C Shift', $row['shift']);
        $invalid = $this->employee('INVALID');
        $this->assignment($invalid, ['source_result_hash' => null]);
        $row = collect($this->snapshot()['rows'])->firstWhere('employee_id', 'INVALID');
        self::assertFalse($row['assignment_verified']);
        self::assertSame('unverified', $row['assignment_category']);
        self::assertNull($row['shift']);
    }

    public function test_superseded_published_assignment_is_selected_at_its_historical_date_without_remote_calls(): void
    {
        $employee = $this->employee('CORRECTION');
        $first = $this->assignment($employee);
        $first->update(['superseded_at' => '2026-10-21 04:00:00']);
        $this->assignment($employee, ['shift_label' => 'Days', 'created_at' => '2026-10-21 04:00:00']);
        self::assertSame('B Shift', $this->snapshot()['rows'][0]['shift']);
        self::assertSame('Days', $this->snapshot([], '2026-10-21')['rows'][0]['shift']);
        Http::assertNothingSent();
    }

    public function test_numbered_float_keeps_published_shift_and_floating_status_without_guessing_division_from_a_suffix(): void
    {
        $employee = $this->employee('FLOAT');
        $this->assignment($employee, ['unit_label' => 'Float 2', 'bid_selection_label' => 'Float 2', 'position_label' => 'Firefighter #2 (R)']);
        $row = $this->snapshot(['floating' => true])['rows'][0];
        self::assertSame('B Shift', $row['shift']);
        self::assertTrue($row['assignment_verified']);
        self::assertTrue($row['is_floating']);
        self::assertSame('unverified', $row['assignment_category']);
        self::assertTrue($row['has_review']);
        self::assertCount(0, $this->snapshot(['assignment' => 'rescue'])['rows']);
    }

    public function test_paper_review_provenance_is_required_and_inactive_attendees_remain_exceptions(): void
    {
        $employee = $this->employee('NO_REVIEW');
        $inactive = $this->employee('INACTIVE');
        $inactive->update(['roster_status' => 'retired']);
        $module = $this->module('Required');
        $this->paper($employee, $this->trainingSession($module, 'paper'), ['reviewed_by' => null, 'reviewed_at' => null]);
        $this->paper($employee, $this->trainingSession($module, 'paper'), ['source_page' => 3]);
        $this->digital($inactive, $this->trainingSession($module, 'submitted'));
        $data = $this->snapshot();
        self::assertCount(1, $data['rows']);
        self::assertSame(0, $data['rows'][0]['covered_count']);
        self::assertNotEmpty(array_filter($data['exceptions'], fn ($exception): bool => $exception['type'] === 'outside_active_population'));
    }

    public function test_new_sessions_voids_and_opt_in_are_reflected_without_a_cache(): void
    {
        $employee = $this->employee('LIVE');
        $module = $this->module('Required');
        self::assertCount(0, $this->snapshot()['sessions']);
        $session = $this->trainingSession($module, 'submitted');
        $attendance = $this->digital($employee, $session);
        self::assertSame(1, $this->snapshot()['rows'][0]['covered_count']);
        $attendance->update(['voided_at' => now()]);
        self::assertSame(0, $this->snapshot()['rows'][0]['covered_count']);
        self::assertCount(1, $this->snapshot()['sessions']);
        $this->course->update(['track_attendance_matrix' => false]);
        try {
            $this->snapshot();
            self::fail('Disabled course reporting must be inaccessible.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
        $this->course->update(['track_attendance_matrix' => true]);
        self::assertCount(1, $this->snapshot()['sessions']);
    }

    public function test_session_columns_follow_module_order_and_keep_distinct_deliveries_on_the_same_date(): void
    {
        $first = $this->module('Module 1');
        $second = $this->module('Module 2');
        $secondDelivery = $this->trainingSession($second, 'submitted');
        $firstDelivery = $this->trainingSession($first, 'submitted');
        $repeatDelivery = $this->trainingSession($first, 'submitted');
        $unassigned = $this->trainingSession(null, 'submitted');
        self::assertSame([$firstDelivery->id, $repeatDelivery->id, $secondDelivery->id, $unassigned->id], array_column($this->snapshot()['sessions'], 'id'));
    }

    public function test_report_and_export_enforce_existing_training_authorization(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => 'active']));
        foreach ([fn () => $this->snapshot(), fn () => app(TrainingMatrixExportService::class)->bytes($this->course)] as $read) {
            try {
                $read();
                self::fail('Unprivileged members cannot read department reports.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_current_view_xlsx_has_exact_filtered_statuses_real_dates_literal_strings_and_no_ink(): void
    {
        $employee = $this->employee('00001', 'Firefighter', '=1+1');
        $this->assignment($employee, ['position_label' => 'Firefighter DE #1']);
        $this->employee('OTHER');
        $module = $this->module('Required');
        $session = $this->trainingSession($module, 'submitted', ['starts_at' => '2026-10-20T14:30:00Z', 'location' => 'Synthetic classroom']);
        $attendance = $this->digital($employee, $session);
        $attendance->update(['signature_signed_at' => CarbonImmutable::parse('2026-10-20T16:00:00Z')]);
        $filters = ['de_bid' => true];
        $snapshot = $this->snapshot($filters);
        $bytes = app(TrainingMatrixExportService::class)->bytes($this->course, $filters, false, 'sessions');
        $book = $this->readWorkbook($bytes);
        try {
            $sheet = $book->getSheetByName('Current View');
            self::assertSame(2, $sheet->getHighestDataRow());
            self::assertSame('00001', $sheet->getCell('A2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            self::assertSame('=1+1', $sheet->getCell('B2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            self::assertSame($snapshot['rows'][0]['session_cells'][$session->id]['label'], $sheet->getCell('M2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());
            self::assertSame(DataType::TYPE_NUMERIC, $book->getSheetByName('Sessions')->getCell('C2')->getDataType());
            self::assertSame('2026-10-20 10:30', $book->getSheetByName('Sessions')->getCell('D2')->getFormattedValue());
            self::assertSame('2026-10-20 16:00', $book->getSheetByName('Attendance Evidence')->getCell('H2')->getFormattedValue());
            self::assertSame('A Shift', $book->getSheetByName('Attendance Evidence')->getCell('N2')->getValue());
            self::assertSame('2026-10-20 14:00', $book->getSheetByName('Attendance Evidence')->getCell('O2')->getFormattedValue());
            self::assertSame('C2', $sheet->getFreezePane());
            foreach ($book->getAllSheets() as $tab) {
                self::assertCount(0, $tab->getDrawingCollection());
                foreach ($tab->getCellCollection()->getCoordinates() as $coordinate) {
                    self::assertNotSame(DataType::TYPE_FORMULA, $tab->getCell($coordinate)->getDataType());
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_complete_workbook_contains_all_requested_categories_and_redacted_source_exceptions(): void
    {
        $employee = $this->employee('BOOK');
        $this->assignment($employee);
        $module = $this->module('Required');
        $this->paper(null, $this->trainingSession($module, 'paper'));
        $accepted = $this->paper($employee, $this->trainingSession($module, 'paper'));
        $book = $this->readWorkbook(app(TrainingMatrixExportService::class)->bytes($this->course, ['search' => 'NO_MATCH'], true));
        try {
            self::assertSame(['Overview', 'All Personnel', 'Officers', 'Captains', 'Lieutenants', 'Rescue Officers', 'Combat Officers',
                'All Firefighters', 'Rescue FF', 'Combat FF', 'DE Bid', 'Missing Training', 'Session Detail', 'Sessions', 'Attendance Evidence', 'Import-Data Exceptions'], $book->getSheetNames());
            self::assertSame('BOOK', $book->getSheetByName('All Personnel')->getCell('A2')->getValue());
            self::assertSame('paper_pending', $book->getSheetByName('Import-Data Exceptions')->getCell('A2')->getValue());
            self::assertSame('Verified paper roster', $book->getSheetByName('Attendance Evidence')->getCell('F2')->getValue());
            self::assertSame($accepted->sourceImport->source_sha256, $book->getSheetByName('Attendance Evidence')->getCell('I2')->getValue());
            self::assertSame(2, $book->getSheetByName('Attendance Evidence')->getCell('J2')->getValue());
            self::assertSame('overflow-1', $book->getSheetByName('Attendance Evidence')->getCell('K2')->getValue());
            foreach ($book->getAllSheets() as $sheet) {
                self::assertStringNotContainsString('PRIVATE_TRANSCRIPTION', json_encode($sheet->toArray(), JSON_THROW_ON_ERROR));
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_department_size_projection_uses_bounded_queries_and_preserves_all_session_cells(): void
    {
        $modules = [];
        for ($i = 0; $i < 4; $i++) {
            $modules[] = $this->module('Module '.$i);
            for ($j = 0; $j < 4; $j++) {
                $this->trainingSession($modules[$i], 'submitted');
            }
        }
        for ($i = 0; $i < 300; $i++) {
            $this->employee('PERF-'.$i);
        }
        $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = microtime(true);
        $memory = memory_get_usage(true);
        $data = $this->snapshot();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(10, $queries);
        self::assertCount(300, $data['rows']);
        self::assertCount(16, $data['rows'][299]['session_cells']);
        fwrite(STDOUT, sprintf("MATRIX_SYNTHETIC personnel=300 modules=4 sessions=16 queries=%d seconds=%.4f memory_delta=%d\n", $queries, microtime(true) - $started, memory_get_usage(true) - $memory));
        $started = microtime(true);
        $memory = memory_get_usage(true);
        $bytes = app(TrainingMatrixExportService::class)->bytes($this->course, [], true);
        self::assertStringStartsWith('PK', $bytes);
        fwrite(STDOUT, sprintf("MATRIX_XLSX_SYNTHETIC personnel=300 modules=4 sessions=16 seconds=%.4f bytes=%d memory_delta=%d\n", microtime(true) - $started, strlen($bytes), memory_get_usage(true) - $memory));
    }

    private function snapshot(array $filters = [], string $asOf = '2026-10-20'): array
    {
        return app(TrainingAttendanceMatrixService::class)->snapshot($this->course, $filters, $asOf);
    }

    private function employee(string $number, string $rank = 'Firefighter', ?string $name = null): Employee
    {
        return Employee::query()->create(['employee_id' => $number, 'name' => $name ?? 'Synthetic '.$number,
            'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used-for-authentication']);
    }

    private function assignment(Employee $employee, array $overrides = []): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->forceCreate(array_replace([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027',
            'bid_session_id' => 'synthetic-matrix', 'position_id' => 'A101', 'rank_label' => 'Firefighter', 'shift_label' => 'B Shift',
            'station_label' => 'Station 1', 'division_label' => 'Operations', 'unit_label' => 'Combat Float', 'position_label' => 'Firefighter #2',
            'bid_selection_label' => 'Combat Float', 'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award', 'a_day_label' => 'Group 1',
            'picked_at' => '2026-10-01T12:00:00Z', 'idempotency_key' => (string) Str::uuid(), 'is_forced' => false,
            'source_sequence' => 1, 'payload_hash' => str_repeat('a', 64), 'source_result_hash' => str_repeat('b', 64),
            'source_workbook_sha256' => str_repeat('c', 64), 'created_at' => '2026-10-01 12:00:00',
        ], $overrides));
    }

    private function module(string $name, array $overrides = []): TrainingModule
    {
        return TrainingModule::query()->create(array_replace(['training_course_id' => $this->course->id, 'name' => $name,
            'sort_order' => 0, 'is_required' => true, 'created_by' => $this->admin->id], $overrides));
    }

    private function trainingSession(?TrainingModule $module, string $status, array $overrides = []): TrainingSession
    {
        return TrainingSession::query()->create(array_replace(['training_course_id' => $this->course->id, 'training_module_id' => $module?->id,
            'public_id' => (string) Str::ulid(), 'course_name' => $this->course->name, 'classifications' => ['STC'],
            'held_on' => '2026-10-20', 'status' => $status, 'attendance_source' => $status === 'paper' ? 'paper' : 'digital',
            'created_by' => $this->admin->id], $overrides));
    }

    private function digital(Employee $employee, TrainingSession $session): TrainingAttendance
    {
        return TrainingAttendance::query()->create(['training_session_id' => $session->id, 'employee_profile_id' => $employee->id,
            'snapshot' => ['rank' => 'Firefighter', 'shift' => 'A Shift', 'as_of' => '2026-10-20'],
            'signature' => ['disk' => 'local', 'path' => 'private/source.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)],
            'signed_at' => CarbonImmutable::parse('2026-10-20T14:00:00Z'), 'client_submission_id' => (string) Str::uuid(), 'request_hash' => str_repeat('e', 64)]);
    }

    private function paper(?Employee $employee, TrainingSession $session, array $overrides = []): TrainingPaperAttendance
    {
        $source = TrainingPaperRosterImport::query()->create(['training_session_id' => $session->id, 'source_sha256' => hash('sha256', (string) $session->id),
            'archive_sha256' => str_repeat('b', 64), 'manifest_sha256' => str_repeat('c', 64), 'source_filename' => 'synthetic.pdf',
            'disk' => 'local', 'path' => 'private/source.pdf', 'page_count' => 2, 'importer_version' => 'synthetic-test',
            'imported_by' => $this->admin->id, 'imported_at' => now()]);

        return TrainingPaperAttendance::query()->create(array_replace(['training_paper_roster_import_id' => $source->id,
            'training_session_id' => $session->id, 'employee_profile_id' => $employee?->id, 'source_page' => 2, 'source_row' => 'overflow-1',
            'raw_transcription' => 'PRIVATE_TRANSCRIPTION', 'evidence_present' => true, 'review_status' => 'approved',
            'reviewed_by' => $this->admin->id, 'reviewed_at' => now()], $overrides));
    }

    private function readWorkbook(string $bytes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'matrix-test-');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
