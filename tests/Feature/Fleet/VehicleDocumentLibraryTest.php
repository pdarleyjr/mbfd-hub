<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Enums\AccountStatus;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentSource;
use App\Services\Fleet\VehicleDocumentLibrary;
use App\Support\Fleet\VehicleDocumentAccess;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;
use ZipArchive;

final class VehicleDocumentLibraryTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        Storage::fake('local');
        config(['filesystems.private' => 'local', 'policy-library.scanner_enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_uploads_keep_independent_metadata_original_names_and_verified_bytes(): void
    {
        $actor = $this->manager();
        $vehicle = $this->vehicle();
        $library = app(VehicleDocumentLibrary::class);
        $first = $library->upload(UploadedFile::fake()->createWithContent('Accident Original.pdf', $this->pdf('one')), $vehicle, $actor, ['title' => 'Collision report', 'category' => 'accidents']);
        $second = $library->upload(UploadedFile::fake()->createWithContent('Repair Original.pdf', $this->pdf('two')), $vehicle, $actor, ['title' => 'Repair estimate', 'category' => 'repairs']);

        self::assertSame('Collision report', $first->fresh()->title);
        self::assertSame('Accident Original.pdf', $first->original_filename);
        self::assertSame('repairs', $second->category);
        self::assertSame($actor->getKey(), $first->uploaded_by);
        self::assertTrue($first->is_sensitive);
        self::assertSame(2, $vehicle->documents()->count());
        $stream = $library->verifiedStream($first);
        try {
            self::assertSame($this->pdf('one'), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_repeated_import_preserves_source_occurrences_and_one_physical_original(): void
    {
        $vehicle = $this->vehicle();
        $other = $this->vehicle('002-13');
        $path = $this->source($this->pdf('source'));
        $originalHash = hash_file('sha256', $path);
        $library = app(VehicleDocumentLibrary::class);
        $metadata = ['title' => 'Historical accident', 'original_filename' => 'report.pdf', 'source_relative_path' => '2018/report.pdf', 'import_batch' => 'corpus-1'];
        $first = $library->ingest($path, $metadata, [$vehicle->getKey()]);
        $again = $library->ingest($path, $metadata, [$vehicle->getKey()]);
        $library->ingest($path, array_merge($metadata, ['source_relative_path' => '2018/second-copy.pdf']), [$other->getKey()]);

        self::assertSame($first->getKey(), $again->getKey());
        self::assertSame(1, VehicleDocument::query()->count());
        self::assertSame(2, VehicleDocumentSource::query()->count());
        self::assertSame(2, $first->vehicles()->count());
        self::assertCount(1, Storage::disk('local')->allFiles('vehicle-documents'));
        self::assertSame($originalHash, hash_file('sha256', $path));
    }

    public function test_unmatched_documents_remain_preserved_without_creating_vehicles(): void
    {
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), [
            'original_filename' => 'unidentified.pdf', 'review_status' => 'unmatched', 'source_relative_path' => 'unknown/report.pdf',
        ], []);

        self::assertSame('unmatched', $document->review_status);
        self::assertSame(0, UnitMasterVehicle::query()->count());
        self::assertSame(0, $document->vehicles()->count());
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_viewer_cannot_upload_and_manager_without_sensitive_permission_cannot_upload_sensitive_records(): void
    {
        $vehicle = $this->vehicle();
        $library = app(VehicleDocumentLibrary::class);
        foreach ([['admin.fleet.view'], ['admin.fleet.view', 'admin.fleet.manage']] as $permissions) {
            $actor = $this->actingAsAdminWith($permissions);
            try {
                $library->upload(UploadedFile::fake()->createWithContent('report.pdf', $this->pdf()), $vehicle, $actor);
                self::fail('Unauthorized upload was accepted.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
        self::assertSame(0, VehicleDocument::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles('vehicle-documents'));
    }

    public function test_sensitive_and_export_permissions_are_independent_and_disabled_accounts_are_denied(): void
    {
        $vehicle = $this->vehicle();
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], [$vehicle->getKey()]);
        $viewer = $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.documents.export']);
        self::assertTrue(VehicleDocumentAccess::canExport($viewer, $vehicle));
        self::assertFalse(VehicleDocumentAccess::canViewDocument($viewer, $document, $vehicle));
        $sensitiveViewer = $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.documents.sensitive']);
        self::assertTrue(VehicleDocumentAccess::canViewDocument($sensitiveViewer, $document, $vehicle));
        self::assertFalse(VehicleDocumentAccess::canExport($sensitiveViewer, $vehicle));
        $sensitiveViewer->forceFill(['account_status' => AccountStatus::Disabled])->save();
        self::assertFalse(VehicleDocumentAccess::canViewDocument($sensitiveViewer, $document, $vehicle));
    }

    public function test_download_is_byte_exact_private_and_cannot_be_rebound_to_another_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $other = $this->vehicle('002-13');
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'Accident report.pdf'], [$vehicle->getKey()]);
        $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.documents.sensitive']);

        $response = $this->get(route('vehicle-documents.download', ['vehicle' => $vehicle, 'document' => $document]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertSame($this->pdf(), $response->streamedContent());
        self::assertSame(1, $document->events()->where('action', 'download')->count());
        $this->get(route('vehicle-documents.download', ['vehicle' => $other, 'document' => $document]))->assertNotFound();
    }

    public function test_direct_download_and_preview_require_sensitive_access(): void
    {
        $vehicle = $this->vehicle();
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], [$vehicle->getKey()]);
        $this->actingAsAdminWith(['admin.fleet.view']);
        foreach (['download', 'preview'] as $action) {
            $this->get(route('vehicle-documents.'.$action, ['vehicle' => $vehicle, 'document' => $document]))->assertNotFound();
        }
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        $this->get(route('vehicle-documents.download', ['vehicle' => $vehicle, 'document' => $document]))->assertNotFound();
    }

    public function test_forged_file_extension_and_invalid_pdf_are_rejected_without_records(): void
    {
        $actor = $this->manager();
        $vehicle = $this->vehicle();
        foreach ([['payload.php', $this->pdf()], ['report.pdf', '<?php echo 1;']] as [$name, $bytes]) {
            try {
                app(VehicleDocumentLibrary::class)->upload(UploadedFile::fake()->createWithContent($name, $bytes), $vehicle, $actor);
                self::fail('Unsafe file accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('files', $exception->errors());
            }
        }
        self::assertSame(0, VehicleDocument::query()->count());
    }

    public function test_office_archives_with_traversal_or_macros_are_rejected(): void
    {
        foreach (['../payload.php', 'word/vbaProject.bin'] as $badEntry) {
            $path = $this->source('');
            $archive = new ZipArchive;
            self::assertTrue($archive->open($path, ZipArchive::OVERWRITE));
            $archive->addFromString('[Content_Types].xml', '<Types/>');
            $archive->addFromString('word/document.xml', '<document/>');
            $archive->addFromString($badEntry, 'unsafe');
            $archive->close();
            try {
                app(VehicleDocumentLibrary::class)->inspectFile($path, 'report.docx');
                self::fail('Unsafe archive accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('files', $exception->errors());
            }
        }
    }

    public function test_scanner_failure_does_not_create_a_document_or_stored_file(): void
    {
        config(['policy-library.scanner_enabled' => true, 'policy-library.clamd_host' => '127.0.0.1', 'policy-library.clamd_port' => 1]);
        try {
            app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], []);
            self::fail('An unchecked file was accepted while scanning was required.');
        } catch (ValidationException $exception) {
            self::assertSame(0, VehicleDocument::query()->count());
            self::assertSame([], Storage::disk('local')->allFiles('vehicle-documents'));
        }
    }

    public function test_failed_storage_verification_leaves_no_broken_database_record(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], []);
            self::fail('A failed file write created a record.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('could not be saved', $exception->getMessage());
            self::assertSame(0, VehicleDocument::query()->count());
        }
    }

    public function test_tampered_stored_bytes_fail_before_any_download(): void
    {
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], []);
        Storage::disk('local')->put($document->path, $this->pdf('tampered'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check');
        app(VehicleDocumentLibrary::class)->verifiedStream($document);
    }

    public function test_metadata_and_relationship_corrections_preserve_originals_and_record_the_actor(): void
    {
        $actor = $this->manager();
        $vehicle = $this->vehicle();
        $other = $this->vehicle('002-13');
        $library = app(VehicleDocumentLibrary::class);
        $document = $library->upload(UploadedFile::fake()->createWithContent('report.pdf', $this->pdf()), $vehicle, $actor);
        $updated = $library->updateMetadata($document, $actor, ['title' => 'Corrected title', 'category' => 'legal-correspondence', 'legal_hold' => true], [$other->getKey()]);

        self::assertSame('Corrected title', $updated->title);
        self::assertTrue($updated->legal_hold);
        self::assertSame('Legal Correspondence', $updated->categoryLabel());
        self::assertSame([$other->getKey()], $updated->vehicles()->pluck('unit_master_vehicles.id')->all());
        self::assertSame($document->sha256, $updated->sha256);
        self::assertSame($actor->getKey(), $updated->events()->first()->user_id);
        self::assertArrayHasKey('legal-correspondence', VehicleDocument::categoryOptions());
        try {
            $library->updateMetadata($updated, $actor, ['legal_hold' => false], [$other->getKey()]);
            self::fail('A routine metadata edit released a legal hold.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('legal_hold', $exception->errors());
            self::assertTrue($updated->fresh()->legal_hold);
        }
    }

    public function test_original_file_fields_and_audit_events_cannot_be_replaced_or_deleted(): void
    {
        $document = app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], []);
        try {
            $document->update(['path' => 'different.bin']);
            self::fail('The original was replaceable.');
        } catch (LogicException) {
            self::assertNotSame('different.bin', $document->fresh()->path);
        }
        try {
            $document->events()->first()->delete();
            self::fail('Audit history was deletable.');
        } catch (LogicException) {
            self::assertSame(1, $document->events()->count());
        }
        $this->expectException(LogicException::class);
        $document->delete();
    }

    public function test_public_disk_configuration_is_rejected(): void
    {
        config(['filesystems.private' => 'public']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private storage');
        app(VehicleDocumentLibrary::class)->ingest($this->source($this->pdf()), ['original_filename' => 'report.pdf'], []);
    }

    private function manager(): User
    {
        return $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.manage', 'admin.fleet.documents.sensitive', 'admin.fleet.documents.export']);
    }

    private function vehicle(string $number = '002-12'): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(['veh_number' => $number, 'make' => 'Ford', 'model' => 'F-150', 'year' => '2022']);
    }

    private function source(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-vehicle-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function pdf(string $marker = 'test'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ".$marker."\n%%EOF\n";
    }
}
