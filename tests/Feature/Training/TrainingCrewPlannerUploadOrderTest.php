<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Training\CrewPlanner\TeleStaffPdfSandbox;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent native-upload regressions; all PDFs and identities are synthetic. */
final class TrainingCrewPlannerUploadOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(app()->environment('testing'));
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        $this->actor = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->course = TrainingCourse::query()->create(['name' => 'Synthetic upload-order course',
            'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $this->actor->id]);
        foreach (['90001' => 'Captain', '90002' => 'Firefighter'] as $number => $rank) {
            Employee::query()->create(['employee_id' => (string) $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
    }

    public static function managementUploads(): array
    {
        return [
            'training administrator uploads first' => ['training_admin', false],
            'training administrator selected a course before uploading' => ['training_admin', true],
            'super administrator uploads first' => ['super_admin', false],
            'super administrator selected a course before uploading' => ['super_admin', true],
        ];
    }

    #[DataProvider('managementUploads')]
    public function test_management_can_upload_before_or_after_choosing_a_course(string $role, bool $selected): void
    {
        $this->signIn($role);
        $component = Livewire::test(TrainingCrewPlanner::class);
        if ($selected) {
            $component->set('courseId', $this->course->id)->assertHasNoErrors();
        }
        $id = $component->get('draftId');
        $store = app(TrainingCrewPlannerDraftStore::class);
        // Choosing a course applies it immediately; without a choice nothing is applied.
        self::assertSame($selected ? $this->course->id : null, $store->get($this->actor, $id)['course_id']);
        $before = $this->sourceHashes();
        DB::statement('PRAGMA query_only = ON');
        try {
            $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf());
            $path = $file->getPathname();
            $this->post(route('training.crew-planner.upload'), ['draft_id' => $id,
                'version' => $component->get('version'), 'roster_pdf' => $file])
                ->assertRedirect()->assertSessionHasNoErrors()
                ->assertSessionHas('crew_planner_notice', fn (string $notice): bool => $notice !== '');
            self::assertFileDoesNotExist($path);
            $uploaded = $store->get($this->actor, $id);
            self::assertSame($selected ? $this->course->id : null, $uploaded['course_id']);
            self::assertNull($uploaded['module_id']);
            self::assertSame('2026-10-21', $uploaded['metadata']['schedule_date']);
            self::assertSame('B', $uploaded['metadata']['shift']);
            self::assertCount(2, $uploaded['staff']);
            self::assertNotEmpty($uploaded['staff']['90001']['working_intervals']);
            self::assertSame([], $uploaded['selections']);
            $component->call('reloadProposal')->assertHasNoErrors()
                ->set('courseId', $this->course->id)->call('applyScope')->assertHasNoErrors();
            $applied = $store->get($this->actor, $id);
            self::assertSame($this->course->id, $applied['course_id']);
            self::assertSame($uploaded['metadata'], $applied['metadata']);
            self::assertSame($uploaded['staff'], $applied['staff']);
            self::assertSame($before, $this->sourceHashes());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    public function test_viewer_cannot_upload_even_when_the_proposal_has_no_course(): void
    {
        $this->signIn('training_viewer');
        $store = app(TrainingCrewPlannerDraftStore::class);
        $draft = $store->create($this->actor);
        $before = $this->sourceHashes();
        DB::statement('PRAGMA query_only = ON');
        try {
            $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf());
            $path = $file->getPathname();
            $this->post(route('training.crew-planner.upload'), ['draft_id' => $draft['id'],
                'version' => $draft['version'], 'roster_pdf' => $file])->assertForbidden();
            self::assertFileDoesNotExist($path);
            self::assertSame($draft, $store->get($this->actor, $draft['id']));
            self::assertSame($before, $this->sourceHashes());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    public static function processingFailures(): array
    {
        return [
            'invalid report' => [20, 'invalid_report'],
            'failed server process' => [255, 'process_failed'],
            'unexpected staffing analysis failure' => [0, 'staffing_analysis_failed'],
        ];
    }

    #[DataProvider('processingFailures')]
    public function test_processing_failure_has_private_fixed_diagnostics_and_preserves_the_proposal(int $exit, string $reason): void
    {
        $this->signIn('training_admin');
        $store = app(TrainingCrewPlannerDraftStore::class);
        $draft = $store->create($this->actor);
        $draft = $store->update($this->actor, $draft['id'], 1, fn (array $row): array => [...$row,
            'course_id' => $this->course->id, 'metadata' => ['schedule_date' => '2026-10-20', 'shift' => 'A'],
            'selections' => ['501' => ['slot_id' => null]]]);
        $file = UploadedFile::fake()->createWithContent('DO-NOT-LOG-90001.pdf', $this->pdf());
        $path = $file->getPathname();
        $sourceHash = hash_file('sha256', $path);
        $process = $this->createMock(Process::class);
        $process->method('setTimeout')->willReturnSelf();
        $process->expects(self::once())->method('run')->willReturn($exit);
        $process->method('isSuccessful')->willReturn($exit === 0);
        $process->method('getExitCode')->willReturn($exit);
        $process->method('isRunning')->willReturn(false);
        $process->expects(self::never())->method('getErrorOutput');
        $process->expects(self::never())->method('getCommandLine');
        if ($exit === 0) {
            // Valid sandbox envelope, unusable row: exercise the resolver's unexpected-error path.
            $process->method('getOutput')->willReturn(json_encode(['schedule_date' => '2026-10-21', 'shift' => 'B',
                'source_sha256' => $sourceHash, 'parsed_assignments' => [['raw_employee_id' => '90001',
                    'raw_name' => ['DO-NOT-LOG-PRIVATE-IDENTITY']]]], JSON_THROW_ON_ERROR));
        } else {
            $process->expects(self::never())->method('getOutput');
        }
        $this->app->instance(TeleStaffPdfSandbox::class, new TeleStaffPdfSandbox(static fn (): Process => $process));
        Log::spy();
        $before = $this->sourceHashes();
        DB::statement('PRAGMA query_only = ON');
        try {
            $response = $this->post(route('training.crew-planner.upload'), ['draft_id' => $draft['id'],
                'version' => $draft['version'], 'roster_pdf' => $file]);
            $response->assertRedirect()->assertSessionHasErrors('roster_pdf');
            $reference = null;
            $recordedContext = null;
            Log::shouldHaveReceived('warning')->once()->withArgs(function (string $event, array $context) use ($reason, &$reference, &$recordedContext): bool {
                self::assertSame('training_crew_planner_upload_failed', $event);
                self::assertSame(['reason', 'reference'], array_keys($context));
                self::assertSame($reason, $context['reason']);
                self::assertTrue(Str::isUuid($context['reference']));
                $reference = $context['reference'];
                $recordedContext = $context;

                return true;
            });
            Log::shouldNotHaveReceived('error');
            $message = session('errors')->first('roster_pdf');
            self::assertStringContainsString('Your saved proposal is safe.', $message);
            self::assertStringContainsString('Reference: '.$reference, $message);
            self::assertStringContainsString($exit === 20 ? 'recognize' : 'server processing problem', $message);
            if ($exit !== 20) {
                self::assertStringNotContainsString('Choose a complete', $message);
            }
            $diagnostics = json_encode([$message, $recordedContext], JSON_THROW_ON_ERROR);
            foreach (['DO-NOT-LOG', $path, $sourceHash, 'raw_employee_id', 'exception', 'trace'] as $private) {
                self::assertStringNotContainsString($private, $diagnostics);
            }
            self::assertFileDoesNotExist($path);
            self::assertSame($draft, $store->get($this->actor, $draft['id']));
            self::assertSame($before, $this->sourceHashes());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    private function signIn(string $role): void
    {
        $this->actor->assignRole(Role::findOrCreate($role, 'web'));
        $this->actingAs($this->actor);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('training'));
    }

    private function sourceHashes(): array
    {
        $hashes = [];
        foreach (Schema::getTableListing() as $table) {
            if (! preg_match('/^(?:training_|employees$|employee_|bid_|vacation_|activity_log$)/', $table)) {
                continue;
            }
            $rows = [];
            foreach (DB::table($table)->get() as $row) {
                $values = (array) $row;
                ksort($values);
                $rows[] = json_encode($values, JSON_THROW_ON_ERROR);
            }
            sort($rows, SORT_STRING);
            $hashes[$table] = hash('sha256', implode("\n", $rows));
        }
        ksort($hashes);

        return $hashes;
    }

    /** A real one-page TeleStaff template containing fabricated identifiers only. */
    private function pdf(): string
    {
        $items = [[48, 700, 'Wednesday, October 21, 2026'], [48, 680, 'B Shift'], [48, 660, 'Station 9'], [48, 640, 'Engine 9']];
        foreach (['90001', '90002'] as $index => $id) {
            $y = 620 - 20 * $index;
            array_push($items, [51, $y, 'Firefighter'], [173, $y, 'SYNTHETIC, '.$id], [311, $y, $id], [351, $y, 'SP'],
                [494.7, $y, '08:00'], [515.9, $y, '20:00'], [548, $y, '12']);
        }
        $content = '';
        foreach ($items as [$x, $y, $text]) {
            $content .= $this->pdfText($x, $y, $text, 0.36040324);
        }
        $content .= $this->pdfText(0, 20, '1 of 1', 0.5).$this->pdfText(525, 20, '10/21/2026, 7:00 AM', 0.5);
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function pdfText(float $x, float $y, string $text, float $scale): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return sprintf("q %.8F 0 0 %.8F 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $scale, $scale, $x / $scale, $y / $scale, $text);
    }
}
