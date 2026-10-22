<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\VehicleDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['policy-library.scanner_enabled' => false]);
        $this->directory = storage_path('framework/testing/vehicle-import-'.Str::uuid());
        $this->batch = 'test-'.Str::uuid();
        File::ensureDirectoryExists($this->directory.'/source');
        $this->beforeApplicationDestroyed(function (): void {
            File::deleteDirectory($this->directory);
            File::deleteDirectory(storage_path('app/private/vehicle-document-imports/'.$this->batch));
        });
    }

    public function test_verified_import_is_idempotent_keeps_multi_vehicle_links_and_preserves_sources(): void
    {
        $first = $this->vehicle('002-12');
        $second = $this->vehicle('002-99');
        $row = $this->row('multi-vehicle.pdf', 'verified', [$first->id, $second->id]);
        $manifest = $this->manifest([$row]);

        $this->runImport($manifest)->assertSuccessful();
        $this->runImport($manifest)->assertSuccessful();

        self::assertSame(1, VehicleDocument::query()->count());
        $document = VehicleDocument::query()->sole();
        self::assertSame([$first->id, $second->id], $document->vehicles()->orderBy('unit_master_vehicles.id')->pluck('unit_master_vehicles.id')->all());
        self::assertSame(1, $document->sources()->count());
        self::assertSame($row['sha256'], hash_file('sha256', $this->directory.'/source/multi-vehicle.pdf'));
        self::assertSame($row['sha256'], hash('sha256', Storage::disk('local')->get($document->path)));
        self::assertSame('002-12', $first->fresh()->veh_number);
        $summary = $this->summary();
        self::assertSame(1, $summary['duplicates']);
        self::assertSame(0, $summary['failed']);
    }

    public function test_conflicting_candidates_and_unmatched_documents_are_preserved_without_guessed_links(): void
    {
        $vehicle = $this->vehicle('002-12');
        $conflicting = $this->row('conflicting.pdf', 'needs_review');
        $conflicting['candidate_vehicle_ids'] = [$vehicle->id];
        $conflicting['matched_vehicle_ids'] = [$vehicle->id];
        $unmatched = $this->row('unmatched.pdf', 'unmatched');

        $this->runImport($this->manifest([$conflicting, $unmatched]))->assertSuccessful();

        self::assertSame(2, VehicleDocument::query()->count());
        self::assertSame(0, $vehicle->documents()->count());
        self::assertSame(1, $this->summary()['needs_review']);
        self::assertSame(1, $this->summary()['unmatched']);
        foreach (VehicleDocument::all() as $document) {
            self::assertSame(0, $document->vehicles()->count());
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_source_hash_mismatch_fails_one_row_without_blocking_valid_following_rows(): void
    {
        $vehicle = $this->vehicle('002-12');
        $wrong = $this->row('wrong.pdf', 'verified', [$vehicle->id]);
        $originalHash = $wrong['sha256'];
        $wrong['sha256'] = str_repeat('0', 64);
        $valid = $this->row('valid.pdf', 'verified', [$vehicle->id]);

        $this->runImport($this->manifest([$wrong, $valid]))->assertFailed();

        self::assertSame(1, VehicleDocument::query()->count());
        self::assertSame($valid['sha256'], VehicleDocument::query()->sole()->sha256);
        self::assertSame(1, $this->summary()['failed']);
        self::assertSame(1, $this->summary()['imported_sources']);
        self::assertSame($originalHash, hash_file('sha256', $this->directory.'/source/wrong.pdf'));
    }

    public function test_parent_directory_references_are_rejected_even_when_hashes_match(): void
    {
        $row = $this->row('outside.pdf', 'unmatched');
        File::move($this->directory.'/source/outside.pdf', $this->directory.'/outside.pdf');
        $row['source_relative_path'] = '../outside.pdf';

        $this->runImport($this->manifest([$row]))->assertFailed();

        self::assertSame(0, VehicleDocument::query()->count());
        self::assertSame(1, $this->summary()['failed']);
        self::assertSame($row['sha256'], hash_file('sha256', $this->directory.'/outside.pdf'));
    }

    public function test_validation_only_never_copies_originals_or_creates_document_rows(): void
    {
        $vehicle = $this->vehicle('002-12');
        $row = $this->row('validation.pdf', 'verified', [$vehicle->id]);
        $this->artisan('fleet:import-vehicle-documents', [
            'manifest' => $this->manifest([$row]), 'source-root' => $this->directory.'/source', '--batch' => $this->batch,
        ])->assertSuccessful();

        self::assertSame(0, VehicleDocument::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles('vehicle-documents'));
        self::assertSame($row['sha256'], hash_file('sha256', $this->directory.'/source/validation.pdf'));
    }

    public function test_verified_status_without_evidence_or_with_unknown_vehicle_is_rejected(): void
    {
        $vehicle = $this->vehicle('002-12');
        $withoutEvidence = $this->row('no-evidence.pdf', 'verified', [$vehicle->id]);
        $withoutEvidence['match_evidence'] = [];
        $unknown = $this->row('unknown.pdf', 'verified', [999999]);

        $this->runImport($this->manifest([$withoutEvidence, $unknown]))->assertFailed();

        self::assertSame(0, VehicleDocument::query()->count());
        self::assertSame(2, $this->summary()['failed']);
    }

    public function test_a_bounded_batch_can_resume_without_duplicating_the_first_original(): void
    {
        $first = $this->row('first.pdf', 'unmatched');
        $second = $this->row('second.pdf', 'unmatched');
        $manifest = $this->manifest([$first, $second]);
        $this->runImport($manifest, ['--limit' => 1])->assertSuccessful();
        self::assertSame(1, VehicleDocument::query()->count());

        $this->runImport($manifest)->assertSuccessful();

        self::assertSame(2, VehicleDocument::query()->count());
        self::assertSame(1, $this->summary()['duplicates']);
        self::assertSame(2, $this->summary()['unmatched']);
    }

    private function vehicle(string $number): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(['veh_number' => $number, 'make' => 'Ford', 'year' => '2022']);
    }

    private function row(string $name, string $status, array $ids = []): array
    {
        $bytes = Pdf::loadHTML('<html><body><p>Synthetic import test: '.e($name).'</p></body></html>')->output();
        File::put($this->directory.'/source/'.$name, $bytes);

        return [
            'source_relative_path' => $name, 'original_filename' => $name, 'sha256' => hash('sha256', $bytes),
            'size_bytes' => strlen($bytes), 'match_status' => $status, 'matched_vehicle_ids' => $ids,
            'match_evidence' => ['Synthetic fixture: explicit fleet identifier field'],
            'title' => pathinfo($name, PATHINFO_FILENAME), 'category_slug' => 'accidents',
        ];
    }

    private function manifest(array $rows): string
    {
        $path = $this->directory.'/manifest.jsonl';
        File::put($path, implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows))."\n");

        return $path;
    }

    private function runImport(string $manifest, array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('fleet:import-vehicle-documents', [
            'manifest' => $manifest, 'source-root' => $this->directory.'/source', '--batch' => $this->batch, '--apply' => true, ...$options,
        ]);
    }

    private function summary(): array
    {
        return json_decode(File::get(storage_path('app/private/vehicle-document-imports/'.$this->batch.'/summary.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
