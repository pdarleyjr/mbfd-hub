<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Services\Fleet\VehicleDocumentLibrary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleDocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_verification_detects_tampering_and_preserves_unreferenced_originals(): void
    {
        Storage::fake('local');
        config(['policy-library.scanner_enabled' => false]);
        $source = storage_path('framework/testing/integrity-'.Str::uuid().'.pdf');
        File::ensureDirectoryExists(dirname($source));
        $bytes = Pdf::loadHTML('<p>Synthetic original for integrity verification.</p>')->output();
        File::put($source, $bytes);
        try {
            $document = app(VehicleDocumentLibrary::class)->ingest($source, ['original_filename' => 'integrity.pdf'], []);
            $this->artisan('fleet:verify-vehicle-documents', ['--scan-orphans' => true])->assertSuccessful();
            Storage::disk('local')->put($document->path, 'tampered');
            $this->artisan('fleet:verify-vehicle-documents')->assertFailed();
            Storage::disk('local')->put($document->path, $bytes);
            $orphan = 'vehicle-documents/aa/'.Str::uuid().'.bin';
            Storage::disk('local')->put($orphan, $bytes);
            $this->artisan('fleet:verify-vehicle-documents', ['--scan-orphans' => true])->assertFailed();
            Storage::disk('local')->assertExists($orphan);
            Storage::disk('local')->assertExists($document->path);
        } finally {
            File::delete($source);
        }
    }
}
