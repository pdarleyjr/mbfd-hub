<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\PersonnelReconciliation as ReconciliationPage;
use App\Models\PersonnelReconciliation;
use App\Models\User;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class PersonnelReconciliationPageTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        Storage::fake('local');
    }

    public function test_viewer_can_review_missing_member_proposals_without_upload_or_export_access(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view']);
        $report = $this->report($actor);
        $component = Livewire::test(ReconciliationPage::class)
            ->assertSet('reportId', $report->id)
            ->assertSee('proposed.member@miamibeachfl.gov')
            ->assertSee('Proposed Lieutenant')
            ->assertSee('Proposed Engine 2')
            ->assertActionHidden('upload')->assertActionHidden('export');
        self::assertArrayNotHasKey('rows', $report->toArray());
        self::assertStringNotContainsString('proposed.member@miamibeachfl.gov', DB::table('personnel_reconciliations')->value('rows'));
        // Filament refuses mounting hidden actions; direct calls remain harmless.
        $component->call('mountAction', 'upload')->assertSet('mountedActions', [])
            ->call('callMountedAction')->assertSet('reportId', $report->id);
        self::assertSame(1, PersonnelReconciliation::query()->count());
    }

    public function test_viewer_cannot_call_the_export_method_directly(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view']);
        $this->report($actor);

        Livewire::test(ReconciliationPage::class)->call('exportReport')->assertForbidden();
    }

    public function test_revoked_view_permission_blocks_an_already_mounted_livewire_component(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.export']);
        $this->report($actor);
        $component = Livewire::test(ReconciliationPage::class)->assertSuccessful();
        DB::table('model_has_permissions')->where('model_id', $actor->id)
            ->where('permission_id', Permission::findByName('personnel.directory.view', 'web')->id)->delete();

        $component->call('exportReport')->assertForbidden();
    }

    public function test_members_and_admins_without_personnel_view_cannot_mount_the_report(): void
    {
        $this->actingAsAdminWith([]);
        Livewire::test(ReconciliationPage::class)->assertForbidden();
        $this->actingAs(User::factory()->create(['account_status' => 'active']));
        Livewire::test(ReconciliationPage::class)->assertForbidden();
    }

    public function test_reconcile_and_export_permissions_authorize_different_actions(): void
    {
        $editor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.edit']);
        $this->report($editor);
        $component = Livewire::test(ReconciliationPage::class)->assertActionVisible('upload')->assertActionHidden('export');
        $component->call('exportReport')->assertForbidden();

        $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.export']);
        Livewire::test(ReconciliationPage::class)->assertActionHidden('upload')->assertActionVisible('export')
            ->call('exportReport')->assertFileDownloaded('MBFD-Personnel-Reconciliation-'.now()->format('Y-m-d').'.csv');
    }

    public function test_authorized_upload_stores_only_an_encrypted_report_and_creates_no_personnel_or_accounts(): void
    {
        $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.edit']);
        $users = User::query()->count();
        Livewire::test(ReconciliationPage::class)->callAction('upload', [
            'workbook' => UploadedFile::fake()->createWithContent('synthetic-directory.xlsx', $this->workbook()),
        ])->assertHasNoActionErrors()->assertSee('proposed.member@miamibeachfl.gov');

        self::assertSame(1, PersonnelReconciliation::query()->count());
        self::assertSame(0, DB::table('employees')->count());
        self::assertSame($users, User::query()->count());
        self::assertStringNotContainsString('proposed.member@miamibeachfl.gov', DB::table('personnel_reconciliations')->value('rows'));
        self::assertSame(1, PersonnelReconciliation::query()->sole()->summary['workbook_only']);
    }

    public function test_csv_neutralizes_formula_and_control_prefixes_and_includes_missing_member_fields(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.export']);
        $row = $this->proposal();
        $row['name'] = '＝HYPERLINK("https://example.invalid")';
        $row['workbook']['name'] = '=1+1';
        $row['workbook']['city_email'] = '@SUM(A1)';
        $row['qa_note'] = "\tcontrol-prefix";
        $this->report($actor, [$row]);
        $component = Livewire::test(ReconciliationPage::class);
        $response = $component->instance()->exportReport();
        ob_start();
        try {
            $response->sendContent();
            $csv = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $values = [];
        while (($record = fgetcsv($stream, escape: '')) !== false) {
            self::assertStringStartsWith("'", $record[1]);
            self::assertStringStartsWith("'\t", $record[6]);
            $values[$record[3]] = $record[4];
        }
        fclose($stream);
        self::assertSame('Employee ID', $header[0]);
        self::assertSame("'=1+1", $values['name']);
        self::assertSame("'@SUM(A1)", $values['city_email']);
        self::assertSame('Proposed Lieutenant', $values['rank']);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_proposed_markup_is_rendered_as_text(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view']);
        $row = $this->proposal();
        $row['workbook']['rank'] = '<script>PERSONNEL_MARKUP</script>';
        $this->report($actor, [$row]);

        Livewire::test(ReconciliationPage::class)->assertSee('&lt;script&gt;PERSONNEL_MARKUP&lt;/script&gt;', false)
            ->assertDontSee('<script>PERSONNEL_MARKUP</script>', false);
    }

    public function test_oversized_or_wrong_auxiliary_sheet_is_rejected_without_a_saved_report(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.edit']);
        foreach (['oversized', 'wrong_header'] as $variant) {
            $path = tempnam(sys_get_temp_dir(), 'reconciliation-boundary-');
            try {
                file_put_contents($path, $this->workbook($variant));
                try {
                    app(PersonnelWorkbookReconciler::class)->store($actor, $path, 'invalid.xlsx');
                    self::fail('An unsupported worksheet must be rejected.');
                } catch (ValidationException $exception) {
                    self::assertArrayHasKey('workbook', $exception->errors());
                }
            } finally {
                unlink($path);
            }
        }
        self::assertSame(0, PersonnelReconciliation::query()->count());
    }

    private function report(User $actor, ?array $rows = null): PersonnelReconciliation
    {
        return PersonnelReconciliation::query()->create([
            'uploaded_by' => $actor->id, 'source_name' => 'synthetic-directory.xlsx', 'workbook_sha256' => str_repeat('b', 64),
            'summary' => ['rules' => 'Review only; no personnel changes.', 'sheets' => ['MBFD Directory'],
                'workbook_rows' => 1, 'hub_rows' => 0, 'present_both' => 0, 'workbook_only' => 1, 'hub_only' => 0,
                'conflicts' => 0, 'excluded' => 0, 'missing_email' => 0, 'duplicate_ids' => 0,
                'assignment_view_mismatches' => 0, 'assignment_overrides' => 0],
            'rows' => $rows ?? [$this->proposal()],
        ]);
    }

    private function proposal(): array
    {
        return ['employee_id' => '00999', 'profile_id' => null, 'name' => 'Proposed Member', 'classification' => 'workbook_only',
            'workbook' => ['name' => 'Proposed Member', 'city_email' => 'proposed.member@miamibeachfl.gov',
                'rank' => 'Proposed Lieutenant', 'shift' => 'A', 'a_day' => '1', 'station' => 'Proposed Station 2',
                'unit' => 'Proposed Engine 2', 'bid_position' => 'Proposed Officer', 'email_match_status' => 'Unreviewed'],
            'live' => [], 'differences' => [], 'flags' => [], 'qa_note' => '', 'assignment_override' => []];
    }

    private function workbook(string $variant = ''): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Summary')->setCellValue('A1', 'Synthetic directory test');
        $directory = $book->createSheet()->setTitle('MBFD Directory');
        $directory->fromArray([PersonnelWorkbookReconciler::HEADERS,
            ['Proposed Member', '00999', 'proposed.member@miamibeachfl.gov', 'Proposed Lieutenant', 'A', '1', 'Proposed Station 2', 'Proposed Engine 2', 'Proposed Officer', 'Unreviewed']], null, 'A1', true);
        $book->createSheet()->setTitle('By Assignment')->fromArray([
            ['Shift', 'Station / Division', 'Seat / Unit', 'Bid Position', 'Name', 'Rank / Position', 'Employee ID', 'Email Address', 'A Day'],
            ['A', 'Proposed Station 2', 'Proposed Engine 2', 'Proposed Officer', 'Proposed Member', 'Proposed Lieutenant', '00999', 'proposed.member@miamibeachfl.gov', '1'],
        ], null, 'A1', true);
        $book->createSheet()->setTitle('Email QA')->fromArray([
            ['Employee ID', 'Assignment Name', 'Directory Display Name', 'Email Address', 'Match Status', 'Review Note'],
        ], null, 'A1', true);
        $book->createSheet()->setTitle('Assignment Overrides')->fromArray([
            ['Employee ID', 'Name', 'Override Type', 'Original Shift', 'Original Station', 'Original Seat / Unit', 'Original Position', 'Applied Shift', 'Applied Station', 'Applied Seat / Unit', 'Applied Position', 'Reason / Source'],
        ], null, 'A1', true);
        if ($variant === 'oversized') {
            $directory->setCellValue('A2002', 'Out of bounds');
        } elseif ($variant === 'wrong_header') {
            $book->getSheetByName('Email QA')->setCellValue('A1', 'Unrecognized identity column');
        }
        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $bytes;
    }
}
