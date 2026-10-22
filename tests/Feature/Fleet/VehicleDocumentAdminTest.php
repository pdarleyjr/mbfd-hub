<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\UnitMasterVehicleResource;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ViewUnitMasterVehicle;
use App\Filament\Resources\UnitMasterVehicleResource\RelationManagers\DocumentsRelationManager;
use App\Models\UnitMasterVehicle;
use App\Models\VehicleDocument;
use App\Services\Fleet\VehicleDocumentLibrary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class VehicleDocumentAdminTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
        Storage::fake('local');
        config(['policy-library.scanner_enabled' => false]);
    }

    public function test_navigation_is_clear_and_the_existing_vehicle_profile_route_is_preserved(): void
    {
        $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle('002-12');

        self::assertSame('Master Vehicle Inventory', UnitMasterVehicleResource::getNavigationLabel());
        self::assertStringEndsWith('/admin/unit-master-vehicles/'.$vehicle->id, UnitMasterVehicleResource::getUrl('view', ['record' => $vehicle]));
        $this->get('/admin/unit-master-vehicles/'.$vehicle->id)->assertOk()->assertSee('Documents');
    }

    public function test_batch_upload_keeps_each_documents_metadata_and_retry_does_not_duplicate_originals(): void
    {
        $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle('002-12');
        $firstBytes = $this->pdf('Synthetic accident report');
        $secondBytes = $this->pdf('Synthetic repair estimate');
        $component = $this->documents($vehicle)->mountTableAction('addDocuments')
            ->set('mountedTableActionsData.0.files', [
                UploadedFile::fake()->createWithContent('accident.pdf', $firstBytes),
                UploadedFile::fake()->createWithContent('estimate.pdf', $secondBytes),
            ]);
        $metadata = $component->get('mountedTableActionsData.0.metadata');
        self::assertCount(2, $metadata);
        foreach (array_keys($metadata) as $index => $key) {
            $metadata[$key]['title'] = $index === 0 ? 'Accident record' : 'Repair estimate';
            $metadata[$key]['category'] = $index === 0 ? 'accidents' : 'repairs';
        }
        $component->set('mountedTableActionsData.0.metadata', $metadata)
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        self::assertSame(['Accident record', 'Repair estimate'], $vehicle->documents()->orderBy('vehicle_documents.id')->pluck('title')->all());
        self::assertSame(['accidents', 'repairs'], $vehicle->documents()->orderBy('vehicle_documents.id')->pluck('category')->all());
        self::assertSame(['accident.pdf', 'estimate.pdf'], $vehicle->documents()->orderBy('vehicle_documents.id')->pluck('original_filename')->all());
        $this->documents($vehicle)->assertCanSeeTableRecords($vehicle->documents()->get());

        app(VehicleDocumentLibrary::class)->upload(UploadedFile::fake()->createWithContent('accident.pdf', $firstBytes), $vehicle, auth()->user(), [
            'title' => 'Retry', 'category' => 'accidents', 'is_sensitive' => true,
        ]);
        self::assertSame(2, VehicleDocument::query()->count());
        self::assertSame(2, $vehicle->documents()->count());
    }

    public function test_hidden_sensitive_metadata_does_not_appear_in_search_or_category_counts(): void
    {
        $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle('002-12');
        $visible = $this->document($vehicle, 'Visible service document', false);
        $restricted = $this->document($vehicle, 'Confidential accident identity', true);
        $this->actingAsAdminWith(['admin.fleet.view']);

        $this->documents($vehicle)->assertCanSeeTableRecords([$visible])->assertCanNotSeeTableRecords([$restricted])
            ->assertDontSee('Confidential accident identity')
            ->searchTable('Confidential')->assertCountTableRecords(0);
    }

    public function test_viewer_cannot_mount_upload_or_edit_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle('002-12');
        $document = $this->document($vehicle, 'Read-only document', false);
        $this->actingAsAdminWith(['admin.fleet.view']);

        $this->documents($vehicle)->assertTableActionHidden('addDocuments')
            ->assertTableActionHidden('editMetadata', $document);
    }

    public function test_metadata_action_corrects_links_without_changing_any_original_bytes(): void
    {
        $this->actingAsSuperAdmin();
        $first = $this->vehicle('002-12');
        $second = $this->vehicle('002-99');
        $document = $this->document($first, 'Original title', false);
        $originalHash = $document->sha256;

        $this->documents($first)->callTableAction('editMetadata', $document, [
            'title' => 'Corrected title', 'category' => 'repairs', 'description' => 'Synthetic correction note',
            'document_date' => '2025-06-14', 'is_sensitive' => false, 'legal_hold' => true,
            'vehicle_ids' => [$second->id],
        ])->assertHasNoTableActionErrors();

        self::assertSame('Corrected title', $document->fresh()->title);
        self::assertSame([$second->id], $document->fresh()->vehicles()->pluck('unit_master_vehicles.id')->all());
        self::assertTrue($document->fresh()->legal_hold);
        self::assertSame($originalHash, hash('sha256', Storage::disk('local')->get($document->path)));
        $this->get(route('vehicle-documents.download', [$first, $document]))->assertNotFound();
        $this->get(route('vehicle-documents.download', [$second, $document]))->assertOk();
    }

    public function test_authenticated_downloads_preserve_bytes_and_direct_urls_enforce_vehicle_and_sensitive_access(): void
    {
        $this->actingAsSuperAdmin();
        $vehicle = $this->vehicle('002-12');
        $other = $this->vehicle('002-99');
        $document = $this->document($vehicle, 'Synthetic report', true);
        $response = $this->get(route('vehicle-documents.download', [$vehicle, $document]))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf');
        self::assertSame($document->sha256, hash('sha256', $response->streamedContent()));
        $this->get(route('vehicle-documents.download', [$other, $document]))->assertNotFound();

        $this->actingAsAdminWith(['admin.fleet.view']);
        $this->get(route('vehicle-documents.download', [$vehicle, $document]))->assertNotFound();
        $this->get(route('vehicle-documents.preview', [$vehicle, $document]))->assertNotFound();
    }

    public function test_guest_download_requires_authentication(): void
    {
        $vehicle = $this->vehicle('002-12');
        $document = VehicleDocument::query()->create([
            'title' => 'Synthetic report', 'category' => 'other', 'original_filename' => 'report.pdf',
            'disk' => 'local', 'path' => 'vehicle-documents/aa/'.str_repeat('a', 64).'.bin',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
            'source_type' => 'manual_upload', 'review_status' => 'verified', 'is_sensitive' => false,
        ]);
        $vehicle->documents()->attach($document);

        $this->get(route('vehicle-documents.download', [$vehicle, $document]))->assertRedirect();
    }

    private function documents(UnitMasterVehicle $vehicle): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $vehicle, 'pageClass' => ViewUnitMasterVehicle::class])->loadTable();
    }

    private function vehicle(string $number): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(['veh_number' => $number, 'make' => 'Ford', 'model' => 'F-150', 'year' => '2022']);
    }

    private function document(UnitMasterVehicle $vehicle, string $title, bool $sensitive): VehicleDocument
    {
        return app(VehicleDocumentLibrary::class)->upload(UploadedFile::fake()->createWithContent('report.pdf', $this->pdf($title)), $vehicle, auth()->user(), [
            'title' => $title, 'category' => $sensitive ? 'accidents' : 'maintenance', 'is_sensitive' => $sensitive,
        ]);
    }

    private function pdf(string $text): string
    {
        return Pdf::loadHTML('<html><body><p>'.e($text).'</p></body></html>')->output();
    }
}
