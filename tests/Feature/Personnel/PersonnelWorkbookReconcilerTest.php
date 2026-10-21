<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Models\Employee;
use App\Models\PersonnelReconciliation;
use App\Models\User;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PersonnelWorkbookReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_report_preserves_profiles_accounts_exclusions_and_uncertain_email_identity(): void
    {
        $actor = $this->administrator();
        $employee = Employee::query()->create(['employee_id' => '01234', 'name' => 'Roster Test One', 'rank' => 'Firefighter', 'city_email' => 'roster.one@miamibeachfl.gov', 'roster_status' => 'active']);
        $departed = Employee::query()->create(['employee_id' => '01235', 'name' => 'Historical Test', 'roster_status' => 'departed']);
        $before = $employee->fresh()->getRawOriginal();
        $usersBefore = User::query()->count();
        $file = $this->workbook([
            ['Roster Test Changed', '01234', 'changed@miamibeachfl.gov', 'Lieutenant', 'A', '1', 'Station 1', 'Engine 1', 'Driver', 'Probable surname change — review'],
            ['Excluded Test', '01236', '', 'Firefighter', 'B', '2', 'Station 2', 'Engine 2', '', 'Unresolved'],
        ], [
            ['01234', 'Roster Test', 'Roster Test Changed', 'changed@miamibeachfl.gov', 'Surname change — confirmed', 'Confirm before authoritative use.'],
            ['01236', 'Excluded Test', 'Excluded Test', '', 'Unresolved', 'DO NOT ADD TO THE MBFD HUB'],
        ]);
        $report = app(PersonnelWorkbookReconciler::class)->store($actor, $file, 'MBFD_Member_Directory.xlsx');

        self::assertSame(2, $report->summary['workbook_rows']);
        self::assertSame(1, $report->summary['present_both']);
        self::assertSame(1, $report->summary['hub_only']);
        self::assertSame(1, $report->summary['excluded']);
        self::assertSame(0, $report->summary['assignment_view_mismatches']);
        self::assertSame('01234', $report->rows[0]['employee_id']);
        self::assertSame('conflict', $report->rows[0]['classification']);
        self::assertStringContainsString('pending review', implode(' ', $report->rows[0]['flags']));
        self::assertSame('excluded', $report->rows[1]['classification']);
        self::assertSame('hub_only', $report->rows[2]['classification']);
        self::assertSame($before, $employee->fresh()->getRawOriginal());
        self::assertSame('departed', $departed->fresh()->roster_status);
        self::assertSame($usersBefore, User::query()->count());
        self::assertSame(2, Employee::query()->count());
        self::assertStringNotContainsString('changed@miamibeachfl.gov', DB::table('personnel_reconciliations')->value('rows'));
        self::assertArrayNotHasKey('rows', $report->toArray());
        self::assertSame('Probable surname change — review', app(PersonnelWorkbookReconciler::class)->latestEmailStatuses($actor)[$employee->id]);
    }

    public function test_duplicate_workbook_ids_never_resolve_to_a_personnel_record(): void
    {
        $actor = $this->administrator();
        Employee::query()->create(['employee_id' => '01234', 'name' => 'Roster Test', 'roster_status' => 'active']);
        $row = ['Roster Test', '01234', '', '', '', '', '', '', '', 'Unresolved'];
        $result = app(PersonnelWorkbookReconciler::class)->analyze($actor, $this->workbook([$row, $row]));

        self::assertSame(2, $result['summary']['duplicate_ids']);
        self::assertSame(['identity_review', 'identity_review'], array_column($result['rows'], 'classification'));
        self::assertSame([null, null], array_column($result['rows'], 'profile_id'));
    }

    public function test_view_permission_cannot_upload_or_export_and_revoked_access_is_rechecked(): void
    {
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'personnel.directory.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        self::assertTrue(PersonnelWorkbookReconciler::canView($viewer));
        self::assertFalse(PersonnelWorkbookReconciler::canReconcile($viewer));
        self::assertFalse(PersonnelWorkbookReconciler::canExport($viewer));
        $viewer->forceFill(['account_status' => 'disabled'])->save();
        self::assertFalse(PersonnelWorkbookReconciler::canView($viewer));
        $this->expectException(HttpException::class);
        app(PersonnelWorkbookReconciler::class)->analyze($viewer, 'unread-path.xlsx');
    }

    public function test_wrong_headers_are_rejected_without_saving_a_report(): void
    {
        $file = $this->workbook([], [], ['Incorrect header']);
        try {
            app(PersonnelWorkbookReconciler::class)->store($this->administrator(), $file, 'wrong.xlsx');
            self::fail('Expected header validation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('workbook', $exception->errors());
        }
        self::assertSame(0, PersonnelReconciliation::query()->count());
    }

    public function test_canonical_name_formatting_is_comparison_only_and_does_not_create_false_conflicts(): void
    {
        Employee::query()->create(['employee_id' => '01234', 'name' => 'Roster Test', 'roster_status' => 'active']);
        $result = app(PersonnelWorkbookReconciler::class)->analyze($this->administrator(), $this->workbook([
            ['TEST, ROSTER', '01234', '', '', '', '', '', '', '', 'Exact match'],
        ]));

        self::assertSame('matched', $result['rows'][0]['classification']);
        self::assertSame('TEST, ROSTER', $result['rows'][0]['workbook']['name']);
        self::assertSame('Roster Test', $result['rows'][0]['live']['name']);
    }

    public function test_assignment_override_source_and_uncertainty_remain_available_for_review(): void
    {
        $file = $this->workbook([
            ['Roster Test', '01234', '', '', 'A', '1', 'Station 1', 'Engine 1', 'Driver', 'Unresolved'],
        ], [], null, [['01234', 'Roster Test', 'Topology resolution', 'A', 'Station 1', 'Engine 1', '', 'A', 'Station 1', 'Engine 1', 'Driver', 'Bid position unconfirmed; review source chart.']]);
        $result = app(PersonnelWorkbookReconciler::class)->analyze($this->administrator(), $file);

        self::assertSame(1, $result['summary']['assignment_overrides']);
        self::assertSame('Bid position unconfirmed; review source chart.', $result['rows'][0]['assignment_override']['reason / source']);
        self::assertStringContainsString('unconfirmed value', implode(' ', $result['rows'][0]['flags']));
        self::assertSame(0, Employee::query()->count());
    }

    private function administrator(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole('super_admin');

        return $actor;
    }

    private function workbook(array $rows, array $qa = [], ?array $headers = null, array $overrides = []): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Summary')->setCellValue('A1', 'Synthetic test workbook');
        $directory = $book->createSheet()->setTitle('MBFD Directory');
        $directory->fromArray([$headers ?? PersonnelWorkbookReconciler::HEADERS, ...$rows], null, 'A1', true);
        $assignment = $book->createSheet()->setTitle('By Assignment');
        $assignment->fromArray([['Shift', 'Station / Division', 'Seat / Unit', 'Bid Position', 'Name', 'Rank / Position', 'Employee ID', 'Email Address', 'A Day'], ...array_map(fn ($row): array => [$row[4], $row[6], $row[7], $row[8], $row[0], $row[3], $row[1], $row[2], $row[5]], $rows)], null, 'A1', true);
        $book->createSheet()->setTitle('Email QA')->fromArray([['Employee ID', 'Assignment Name', 'Directory Display Name', 'Email Address', 'Match Status', 'Review Note'], ...$qa], null, 'A1', true);
        $book->createSheet()->setTitle('Assignment Overrides')->fromArray([['Employee ID', 'Name', 'Override Type', 'Original Shift', 'Original Station', 'Original Seat / Unit', 'Original Position', 'Applied Shift', 'Applied Station', 'Applied Seat / Unit', 'Applied Position', 'Reason / Source'], ...$overrides], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-test-');
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
