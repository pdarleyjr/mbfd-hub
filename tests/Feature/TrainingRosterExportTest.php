<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingRosterExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

final class TrainingRosterExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function attendanceCounts(): array
    {
        return array_map(fn (int $count): array => [$count], [0, 1, 16, 17, 32, 33, 50, 100]);
    }

    #[DataProvider('attendanceCounts')]
    public function test_pdf_and_workbook_preserve_all_rows_and_signatures(int $count): void
    {
        $version = $this->version($count, true);
        $service = app(TrainingRosterExportService::class);
        $pdf = $service->bytes($version, 'pdf');
        $document = (new Parser)->parseContent($pdf);
        $pages = max(1, (int) ceil($count / 16));
        self::assertCount($pages, $document->getPages());
        $text = $document->getText();
        self::assertStringContainsString('MBFD TRAINING ROSTER', $text);
        self::assertStringContainsString('CERTIFIED', $text);
        self::assertStringContainsString('SYNTHETIC OIC', $text);
        foreach (TrainingRosterExportService::CLASSIFICATIONS as $label) {
            self::assertStringContainsString($label, $text);
        }
        for ($i = 1; $i <= $count; $i++) {
            self::assertStringContainsString('TESTLAST'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), $text);
        }

        $xlsx = $service->bytes($version, 'xlsx');
        $path = tempnam(sys_get_temp_dir(), 'training-export-');
        file_put_contents($path, $xlsx);
        try {
            $book = IOFactory::load($path);
            self::assertSame(['Printable roster', 'Attendance data'], $book->getSheetNames());
            $data = $book->getSheet(1);
            self::assertSame($count + 1, $data->getHighestDataRow());
            self::assertCount($count + $pages * 2, $book->getSheet(0)->getDrawingCollection());
            if ($count > 0) {
                self::assertSame('00001', $data->getCell('H2')->getValue());
                self::assertSame('=1+1', $data->getCell('J2')->getValue());
                self::assertSame(DataType::TYPE_STRING, $data->getCell('J2')->getDataType());
                self::assertSame($version->snapshot['attendance'][0]['signature']['sha256'], $data->getCell('R2')->getValue());
                self::assertSame($version->snapshot['session']['course_name'], $data->getCell('C2')->getValue());
                self::assertSame($version->snapshot['session']['held_on'], $data->getCell('D2')->getValue());
                self::assertSame($version->snapshot['attendance'][0]['signed_at'], $data->getCell('Q2')->getValue());
                self::assertSame('Certified', $data->getCell('S2')->getValue());
                self::assertSame('00000', $data->getCell('U2')->getValue());
                self::assertSame($version->snapshot['certification']['oic_signature']['sha256'], $data->getCell('W2')->getValue());
                self::assertSame($version->snapshot['attendance'][$count - 1]['snapshot']['employee_id'], $data->getCell('H'.($count + 1))->getValue());
            }
            self::assertSame('A2', $data->getFreezePane());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        self::assertSame($pdf, $service->bytes($version->fresh(), 'pdf'));
        self::assertSame($xlsx, $service->bytes($version->fresh(), 'xlsx'));
        self::assertSame(hash('sha256', $pdf), $version->fresh()->pdf_artifact['sha256']);
    }

    public function test_provisional_zero_attendance_roster_is_available_without_certification(): void
    {
        $version = $this->version(0, false);
        $response = app(TrainingRosterExportService::class)->download($version, 'pdf');
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('MBFD-Training-synthetic-export-verification-2026-10-15-', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('PROVISIONAL', (new Parser)->parseContent($response->getContent())->getText());
    }

    public function test_corrupted_signature_blocks_export_instead_of_omitting_evidence(): void
    {
        $version = $this->version(1, false);
        Storage::disk('local')->put('training/test-signature.png', 'corrupt');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check');
        app(TrainingRosterExportService::class)->bytes($version, 'pdf');
    }

    public function test_corrupted_cached_artifact_is_never_served(): void
    {
        $version = $this->version(1, false);
        app(TrainingRosterExportService::class)->bytes($version, 'xlsx');
        Storage::disk('local')->put($version->fresh()->xlsx_artifact['path'], 'corrupt');
        $this->expectException(\RuntimeException::class);
        app(TrainingRosterExportService::class)->bytes($version->fresh(), 'xlsx');
    }

    public function test_corrupted_oic_signature_blocks_certified_export(): void
    {
        $version = $this->version(0, true);
        Storage::disk('local')->put('training/test-signature.png', 'corrupt');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check');
        app(TrainingRosterExportService::class)->bytes($version, 'xlsx');
    }

    public function test_long_course_location_and_names_use_planned_complete_rows(): void
    {
        $version = $this->version(16, false);
        $snapshot = $version->snapshot;
        $snapshot['session']['course_name'] = str_repeat('Advanced incident command ', 7);
        $snapshot['session']['location'] = str_repeat('Training classroom ', 10);
        $snapshot['attendance'][0]['snapshot']['last_name'] = 'SYNTHETIC VAN DER LONG FAMILY NAME TEST';
        $snapshot['attendance'][0]['snapshot']['first_name'] = 'SYNTHETIC LONG COMPOUND GIVEN NAME';
        // A new immutable snapshot fixture, never an update to an existing version.
        $longVersion = TrainingRosterVersion::query()->create(['training_session_id' => $version->training_session_id, 'revision' => 2, 'snapshot' => $snapshot]);
        $pdf = (new Parser)->parseContent(app(TrainingRosterExportService::class)->bytes($longVersion, 'pdf'));
        self::assertCount(3, $pdf->getPages());
        self::assertStringContainsString('FAMILY NAME TEST', $pdf->getText());
        self::assertStringContainsString('GIVEN NAME', $pdf->getText());
    }

    public function test_maximum_length_names_and_course_keep_certification_on_each_planned_page(): void
    {
        $version = $this->version(16, true);
        $snapshot = $version->snapshot;
        $snapshot['session']['course_name'] = str_repeat('W', 200);
        $snapshot['session']['location'] = str_repeat('W', 200);
        foreach ($snapshot['attendance'] as &$entry) {
            $entry['snapshot']['first_name'] = str_repeat('W', 100);
            $entry['snapshot']['last_name'] = str_repeat('W', 100);
        }
        unset($entry);
        $longVersion = TrainingRosterVersion::query()->create(['training_session_id' => $version->training_session_id, 'revision' => 2, 'snapshot' => $snapshot]);
        $service = app(TrainingRosterExportService::class);
        $pdf = (new Parser)->parseContent($service->bytes($longVersion, 'pdf'));
        self::assertCount(6, $pdf->getPages());
        foreach ($pdf->getPages() as $index => $page) {
            self::assertStringContainsString('COURSE NAME:', $page->getText());
            self::assertStringContainsString('SYNTHETIC OIC', $page->getText());
            self::assertStringContainsString('Page '.($index + 1).' of 6', $page->getText());
        }
        $path = tempnam(sys_get_temp_dir(), 'training-long-export-');
        file_put_contents($path, $service->bytes($longVersion, 'xlsx'));
        try {
            $book = IOFactory::load($path);
            self::assertSame(17, $book->getSheet(1)->getHighestDataRow());
            self::assertSame(str_repeat('W', 100), $book->getSheet(1)->getCell('I17')->getValue());
            self::assertCount(28, $book->getSheet(0)->getDrawingCollection());
            self::assertCount(5, $book->getSheet(0)->getBreaks());
            self::assertSame(['A19', 'A38', 'A57', 'A76', 'A95'], array_keys($book->getSheet(0)->getBreaks()));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_maximum_metadata_with_ordinary_names_keeps_the_footer_with_each_roster(): void
    {
        $version = $this->version(16, true);
        $snapshot = $version->snapshot;
        $snapshot['session']['course_name'] = str_repeat('W', 200);
        $snapshot['session']['location'] = str_repeat('W', 255);
        $longVersion = TrainingRosterVersion::query()->create(['training_session_id' => $version->training_session_id, 'revision' => 2, 'snapshot' => $snapshot]);
        $pdf = (new Parser)->parseContent(app(TrainingRosterExportService::class)->bytes($longVersion, 'pdf'));
        self::assertCount(2, $pdf->getPages());
        foreach ($pdf->getPages() as $index => $page) {
            self::assertStringContainsString('COURSE NAME:', $page->getText());
            self::assertStringContainsString('SYNTHETIC OIC', $page->getText());
            self::assertStringContainsString('Page '.($index + 1).' of 2', $page->getText());
        }
    }

    private function version(int $count, bool $certified): TrainingRosterVersion
    {
        $user = User::factory()->create();
        $course = TrainingCourse::query()->create(['name' => 'SYNTHETIC export verification', 'classifications' => ['EMS Training'], 'created_by' => $user->id]);
        $session = TrainingSession::query()->create(['public_id' => (string) Str::ulid(), 'training_course_id' => $course->id, 'course_name' => $course->name, 'classifications' => $course->classifications, 'held_on' => '2026-10-15', 'status' => $certified ? 'certified' : 'open', 'revision' => 1, 'created_by' => $user->id]);
        $image = imagecreatetruecolor(240, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 20, 20, 20);
        for ($i = 0; $i < 12; $i++) {
            imageline($image, 10 + $i * 17, 10, 23 + $i * 17, 45, $ink);
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('training/test-signature.png', $bytes);
        $signature = ['disk' => 'local', 'path' => 'training/test-signature.png', 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes)];
        $attendance = [];
        for ($i = 1; $i <= $count; $i++) {
            $attendance[] = ['id' => $i, 'employee_profile_id' => $i, 'snapshot' => ['employee_id' => str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'first_name' => $i === 1 ? '=1+1' : 'SYNTHETIC', 'last_name' => 'TESTLAST'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'display_name' => 'Synthetic test '.$i, 'rank' => 'Firefighter', 'shift' => 'A', 'term_label' => 'Synthetic test term', 'assignment_source' => 'test', 'assignment_id' => (string) $i, 'as_of' => '2026-10-15'], 'signature' => $signature, 'signed_at' => '2026-10-15T14:00:00+00:00'];
        }

        return TrainingRosterVersion::query()->create(['training_session_id' => $session->id, 'revision' => 1, 'snapshot' => ['schema_version' => 1, 'session' => ['id' => $session->id, 'public_id' => $session->public_id, 'course_name' => $course->name, 'classifications' => $course->classifications, 'held_on' => '2026-10-15', 'location' => null, 'starts_at' => null, 'status' => $session->status, 'revision' => 1], 'attendance' => $attendance, 'certification' => $certified ? ['oic_snapshot' => ['employee_id' => '00000', 'display_name' => 'SYNTHETIC OIC'], 'oic_signature' => $signature, 'certified_at' => '2026-10-15T15:00:00+00:00', 'certified_by' => $user->id] : null, 'captured_at' => '2026-10-15T15:00:00+00:00']]);
    }
}
