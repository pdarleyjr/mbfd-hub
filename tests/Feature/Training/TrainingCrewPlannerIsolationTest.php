<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Independent workflow acceptance using a real fabricated PDF and a read-only database. */
final class TrainingCrewPlannerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TrainingCourse $course;

    private array $employees;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $this->course = TrainingCourse::query()->create(['name' => 'Independent synthetic course', 'classifications' => ['STC'],
            'track_attendance_matrix' => false, 'created_by' => $this->admin->id]);
        $this->employees = [];
        foreach (['90001' => 'Captain', '90002' => 'Firefighter'] as $number => $rank) {
            $this->employees[] = Employee::query()->create(['employee_id' => (string) $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
    }

    public function test_real_http_upload_cumulative_livewire_plan_and_both_downloads_leave_every_domain_table_unchanged(): void
    {
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->call('applyScope')->assertHasNoErrors();
        $draftId = $component->get('draftId');
        $version = $component->get('version');
        $before = $this->sourceHashes();
        DB::statement('PRAGMA query_only = ON');
        try {
            $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf());
            $path = $file->getPathname();
            $this->post(route('training.crew-planner.upload'), ['draft_id' => $draftId, 'version' => $version, 'roster_pdf' => $file])->assertRedirect();
            self::assertFileDoesNotExist($path);
            $component->call('reloadProposal')->call('saveSlots')->assertHasNoErrors();
            $slots = $this->draft($draftId)['slots'];
            self::assertCount(2, $slots);
            $component->set('filters.rank', 'Captain')->call('addSelected', true)->assertHasNoErrors();
            self::assertCount(1, $this->draft($draftId)['selections']);
            $component->set('filters.rank', 'Firefighter')->call('addSelected', true)->assertHasNoErrors();
            self::assertCount(2, $this->draft($draftId)['selections']);
            $component->set('filters.rank', 'Captain')->call('addSelected', true)->assertHasNoErrors();
            self::assertCount(2, $this->draft($draftId)['selections']);
            $firstId = (string) $this->employees[0]->id;
            $secondId = (string) $this->employees[1]->id;
            $component->call('removeMember', (int) $firstId)->assertHasNoErrors();
            self::assertCount(1, $this->draft($draftId)['selections']);
            $component->call('undoRemove')->assertHasNoErrors();
            self::assertCount(2, $this->draft($draftId)['selections']);
            $component->set('planSelection', [$firstId])->set('targetSlot', $slots[0]['id'])->call('moveSelected')->assertHasNoErrors();
            $component->set('planSelection', [$secondId])->set('targetSlot', $slots[1]['id'])->call('moveSelected')->assertHasNoErrors();
            $component->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Proposal-2026-10-21.pdf');
            $pdf = $this->downloadBytes($component);
            self::assertStringStartsWith('%PDF-', $pdf);
            $text = (new Parser)->parseContent($pdf)->getText();
            foreach (['Synthetic 90001', 'Synthetic 90002', 'Captain', 'Firefighter', 'Station 9', 'Engine 9', 'Battalion Chief 300'] as $expected) {
                self::assertStringContainsString($expected, $text);
            }
            $component->call('download', 'xlsx')->assertFileDownloaded('MBFD-Training-Proposal-2026-10-21.xlsx');
            $xlsx = $this->downloadBytes($component);
            self::assertStringStartsWith('PK', $xlsx);
            $bookPath = tempnam(sys_get_temp_dir(), 'crew-isolation-xlsx-');
            try {
                file_put_contents($bookPath, $xlsx);
                $book = IOFactory::load($bookPath);
                try {
                    self::assertSame('2', $book->getSheetByName('Summary')->getCell('B10')->getValue());
                    self::assertSame('Captain', $book->getSheetByName('Morning')->getCell('C5')->getValue());
                    self::assertStringContainsString('Engine 9', $book->getSheetByName('Afternoon')->getCell('F5')->getValue());
                    foreach ($book->getAllSheets() as $sheet) {
                        foreach ($sheet->getCoordinates() as $coordinate) {
                            self::assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($coordinate)->getDataType());
                        }
                    }
                } finally {
                    $book->disconnectWorksheets();
                }
            } finally {
                unlink($bookPath);
            }
            // A saved time change keeps both proposed people and requires fresh review.
            $component->set('slotEdits.0.end', '21:00')->call('saveSlots')->assertHasNoErrors();
            self::assertCount(2, $this->draft($draftId)['selections']);
            $component->call('download', 'pdf')->assertHasErrors('confirmReview');
            $component->set('confirmReview', true)->call('download', 'pdf')->assertFileDownloaded();
            self::assertStringContainsString('Requires verification', (new Parser)->parseContent($this->downloadBytes($component))->getText());
            $component->call('discardProposal')->assertHasNoErrors();
            self::assertNotSame($draftId, $component->get('draftId'));
            self::assertSame([], $this->draft($component->get('draftId'))['selections']);
            self::assertSame($before, $this->sourceHashes());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    public function test_forbidden_payload_bad_pdf_foreign_draft_and_stale_upload_do_not_mutate_source_or_proposal(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $draft = $store->create($this->admin);
        $draft = $store->update($this->admin, $draft['id'], 1, fn (array $row): array => [...$row, 'course_id' => $this->course->id]);
        $other = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $foreign = $store->create($other);
        $before = $this->sourceHashes();
        foreach ([
            [['training_attendances' => [['signature' => 'forbidden']]], $draft['id'], $draft['version'], $this->pdf(), 422],
            [[], $draft['id'], $draft['version'], '%PDF-1.4 /JavaScript forbidden', 302],
            [[], $foreign['id'], $foreign['version'], $this->pdf(), 410],
            [[], $draft['id'], 1, $this->pdf(), 409],
        ] as [$extra, $id, $version, $bytes, $status]) {
            $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $bytes);
            $path = $file->getPathname();
            $this->post(route('training.crew-planner.upload'), $extra + ['draft_id' => $id, 'version' => $version, 'roster_pdf' => $file])->assertStatus($status);
            self::assertFileDoesNotExist($path);
            self::assertSame($draft, $store->get($this->admin, $draft['id']));
        }
        self::assertSame($before, $this->sourceHashes());
    }

    public function test_viewer_and_revoked_management_access_are_denied_on_actual_actions(): void
    {
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->call('applyScope');
        $this->admin->removeRole('training_admin');
        $component->call('download', 'pdf')->assertForbidden();
        $this->admin->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf());
        $path = $file->getPathname();
        $this->post(route('training.crew-planner.upload'), ['draft_id' => (string) Str::uuid(), 'version' => 1, 'roster_pdf' => $file])->assertForbidden();
        self::assertFileDoesNotExist($path);
    }

    public function test_anonymous_actor_cannot_open_page_or_analyze_an_upload(): void
    {
        $draft = app(TrainingCrewPlannerDraftStore::class)->create($this->admin);
        $before = $this->sourceHashes();
        $this->logoutCanonicalSession();
        $this->get('/training/crew-planner')->assertRedirect();
        $file = UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf());
        try {
            $this->post(route('training.crew-planner.upload'), ['draft_id' => $draft['id'], 'version' => 1, 'roster_pdf' => $file],
                ['Accept' => 'application/json'])->assertUnauthorized();
            self::assertSame($before, $this->sourceHashes());
        } finally {
            // Middleware prevents controller execution; PHP owns request-temp cleanup in production.
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    private function draft(string $id): array
    {
        return app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $id);
    }

    private function downloadBytes(mixed $component): string
    {
        return base64_decode($component->effects['download']['content'], true);
    }

    private function sourceHashes(): array
    {
        $hashes = [];
        foreach (Schema::getTableListing() as $table) {
            if (preg_match('/^(?:training_|employees$|employee_bid_assignments$)|vacation/i', $table)) {
                $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
                $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }

        return $hashes;
    }

    /** Valid one-page template PDF with fabricated employees, decoded by the real parser. */
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
