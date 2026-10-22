<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Services\Fleet\UnitMasterVehicleProfileExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;
use ZipArchive;

final class UnitMasterVehicleProfileExportTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
        Storage::fake('local');
    }

    public function test_profile_pdf_is_branded_and_delivered_without_an_archive(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();

        $response = app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf');

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $bytes = $this->bytes($response);
        self::assertStringStartsWith('%PDF', $bytes);
        $text = (new Parser)->parseContent($bytes)->getText();
        self::assertStringContainsString('Master Vehicle Inventory', $text);
        self::assertStringContainsString('002-12', $text);
    }

    public function test_xlsx_preserves_identifiers_and_prevents_formula_injection(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle(['assignment' => '=HYPERLINK("https://invalid.test","unsafe")']);
        $response = app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'xlsx');
        $spreadsheet = IOFactory::load($this->temporaryFile($this->bytes($response)));

        try {
            $sheet = $spreadsheet->getSheetByName('Vehicle Profile');
            self::assertNotNull($sheet);
            $rows = $sheet->toArray();
            foreach (['002-12', '00123', '000987654321', $vehicle->assignment] as $value) {
                $row = null;
                foreach ($rows as $index => $values) {
                    if (($values[1] ?? null) === $value) {
                        $row = $index;
                        break;
                    }
                }
                self::assertNotNull($row, 'Missing exported value '.$value);
                self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B'.($row + 1))->getDataType());
            }
            self::assertNotNull($spreadsheet->getSheetByName('Vehicle Documents'));
            self::assertNotNull($spreadsheet->getSheetByName('Inspections'));
            self::assertNotNull($spreadsheet->getSheetByName('Export Information'));
            self::assertSame('A2', $sheet->getFreezePane());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_package_contains_only_selected_intact_originals_and_a_portable_manifest(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $first = $this->document($vehicle, '%PDF-1.4 first original', ['original_filename' => '../../CON.pdf']);
        $second = $this->document($vehicle, '%PDF-1.4 second original', ['original_filename' => '../../CON.pdf']);
        $excluded = $this->document($vehicle, '%PDF-1.4 excluded original');

        $response = app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'xlsx', [$first->id, $second->id]);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        $archive = new ZipArchive;
        self::assertTrue($archive->open($this->temporaryFile($this->bytes($response))));

        try {
            $manifest = json_decode((string) $archive->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $manifest['documents']);
            self::assertSame([$first->id, $second->id], array_column($manifest['documents'], 'id'));
            self::assertNotContains($excluded->id, array_column($manifest['documents'], 'id'));
            foreach ($manifest['documents'] as $index => $entry) {
                self::assertStringStartsWith('Documents/', $entry['path']);
                self::assertStringNotContainsString('..', $entry['path']);
                self::assertStringNotContainsString('\\', $entry['path']);
                $bytes = (string) $archive->getFromName($entry['path']);
                self::assertSame($index === 0 ? '%PDF-1.4 first original' : '%PDF-1.4 second original', $bytes);
                self::assertSame(hash('sha256', $bytes), $entry['sha256']);
                self::assertSame(strlen($bytes), $entry['size_bytes']);
            }
            self::assertNotFalse($archive->getFromName($manifest['profile']['path']));
            self::assertSame(4, $archive->numFiles);
            self::assertSame('00123', $manifest['documents'][0]['report_number']);
            $spreadsheet = IOFactory::load($this->temporaryFile((string) $archive->getFromName($manifest['profile']['path'])));
            try {
                $documentSheet = $spreadsheet->getSheetByName('Vehicle Documents');
                self::assertSame('00123', $documentSheet->getCell('H2')->getValue());
                self::assertSame(DataType::TYPE_STRING, $documentSheet->getCell('H2')->getDataType());
                self::assertSame($manifest['documents'][0]['path'], $documentSheet->getCell('F2')->getHyperlink()->getUrl());
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
            self::assertSame(1, $first->events()->where('action', 'export')->count());
            self::assertSame(1, $second->events()->where('action', 'export')->count());
            self::assertSame(0, $excluded->events()->where('action', 'export')->count());
        } finally {
            $archive->close();
        }
    }

    public function test_an_actor_without_export_access_cannot_generate_a_profile(): void
    {
        $vehicle = $this->vehicle();
        $actor = User::factory()->create(['account_status' => 'active']);
        $this->expectException(HttpException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf');
    }

    public function test_documents_from_another_vehicle_cannot_be_requested_by_id(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $other = $this->vehicle(['veh_number' => '002-99']);
        $document = $this->document($other, '%PDF-1.4 another vehicle');
        $this->expectException(HttpException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf', [$document->id]);
    }

    public function test_export_permission_does_not_bypass_sensitive_document_access(): void
    {
        $actor = $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.documents.export']);
        $vehicle = $this->vehicle();
        $document = $this->document($vehicle, '%PDF-1.4 confidential original', ['is_sensitive' => true]);
        $this->expectException(HttpException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf', [$document->id]);
    }

    public function test_a_tampered_original_prevents_delivery_of_a_partial_package(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $document = $this->document($vehicle, '%PDF-1.4 original');
        Storage::disk('local')->put($document->path, 'tampered');
        $this->expectException(\RuntimeException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf', [$document->id]);
    }

    public function test_missing_original_prevents_delivery_of_a_partial_package(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $document = $this->document($vehicle, '%PDF-1.4 original');
        Storage::disk('local')->delete($document->path);
        $this->expectException(\RuntimeException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'xlsx', [$document->id]);
    }

    public function test_export_limit_is_enforced_before_reading_an_oversized_original(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $document = $this->document($vehicle, '%PDF-1.4 original', ['size_bytes' => 251 * 1024 * 1024]);
        $this->expectException(ValidationException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'pdf', [$document->id]);
    }

    public function test_invalid_format_is_rejected(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->expectException(ValidationException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($this->vehicle(), $actor, 'html');
    }

    public function test_document_count_limit_rejects_an_unbounded_selection(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->expectException(ValidationException::class);

        app(UnitMasterVehicleProfileExporter::class)->download($this->vehicle(), $actor, 'xlsx', range(1, 101));
    }

    public function test_private_staging_is_removed_after_the_package_is_streamed(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle();
        $document = $this->document($vehicle, '%PDF-1.4 cleanup original');
        $directory = storage_path('app/private/vehicle-exports');
        $before = is_dir($directory) ? File::directories($directory) : [];
        $response = app(UnitMasterVehicleProfileExporter::class)->download($vehicle, $actor, 'xlsx', [$document->id]);
        self::assertCount(count($before) + 1, File::directories($directory));

        $this->bytes($response);

        self::assertSame($before, File::directories($directory));
        Storage::disk('local')->assertExists($document->path);
    }

    /** @param array<string, mixed> $attributes */
    private function vehicle(array $attributes = []): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(array_merge([
            'veh_number' => '002-12', 'year' => '2022', 'make' => 'Ford', 'model' => 'F-150',
            'tag_number' => '00123', 'serial_number' => '000987654321', 'dept_code' => 'FIRE',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function document(UnitMasterVehicle $vehicle, string $bytes, array $attributes = []): VehicleDocument
    {
        $sha256 = hash('sha256', $bytes);
        $path = 'vehicle-documents/'.substr($sha256, 0, 2).'/'.$sha256.'.bin';
        Storage::disk('local')->put($path, $bytes);
        $document = VehicleDocument::query()->create(array_merge([
            'title' => 'Accident report', 'category' => 'accidents', 'original_filename' => 'Accident report.pdf',
            'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes),
            'sha256' => $sha256, 'source_type' => 'upload', 'review_status' => 'verified', 'is_sensitive' => false, 'report_number' => '00123',
        ], $attributes));
        $vehicle->documents()->attach($document->id);

        return $document;
    }

    private function bytes(StreamedResponse $response): string
    {
        ob_start();
        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vehicle-export-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(static fn () => is_file($path) ? unlink($path) : null);

        return $path;
    }
}
