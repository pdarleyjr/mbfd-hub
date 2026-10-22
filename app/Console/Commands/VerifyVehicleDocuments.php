<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VehicleDocument;
use App\Services\Fleet\VehicleDocumentLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class VerifyVehicleDocuments extends Command
{
    protected $signature = 'fleet:verify-vehicle-documents {--batch= : Limit to one historical import batch} {--scan-orphans : Inventory unreferenced managed originals without deleting them}';

    protected $description = 'Verify managed vehicle-document hashes and report preserved orphan files';

    public function handle(VehicleDocumentLibrary $library): int
    {
        $query = VehicleDocument::query();
        if ($this->option('batch')) {
            $query->whereHas('sources', fn ($sources) => $sources->where('import_batch', $this->option('batch')));
        }
        $counts = ['checked' => 0, 'verified' => 0, 'failed' => 0, 'orphans' => 0];
        $results = [];
        foreach ($query->lazyById(100) as $document) {
            $counts['checked']++;
            try {
                $stream = $library->verifiedStream($document);
                fclose($stream);
                $counts['verified']++;
            } catch (Throwable $exception) {
                $counts['failed']++;
                $results[] = ['document_id' => $document->id, 'status' => 'integrity_failure', 'error_type' => class_basename($exception)];
            }
        }
        if ($this->option('scan-orphans')) {
            $disk = (string) config('filesystems.private', 'local');
            if ($disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public') {
                $this->error('Refusing to inspect a public document disk.');

                return self::FAILURE;
            }
            $known = VehicleDocument::query()->where('disk', $disk)->pluck('path')->flip();
            foreach (Storage::disk($disk)->allFiles('vehicle-documents') as $path) {
                if (! $known->has($path)) {
                    $counts['orphans']++;
                    $results[] = ['status' => 'unreferenced_original_preserved', 'disk' => $disk, 'path' => $path];
                }
            }
        }
        $directory = storage_path('app/private/vehicle-document-imports');
        File::ensureDirectoryExists($directory, 0700);
        $receipt = $directory.'/integrity-'.now('UTC')->format('Ymd-His-u').'.json';
        File::put($receipt, json_encode(['summary' => $counts, 'exceptions' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        $this->line('Private integrity receipt: '.$receipt);

        return $counts['failed'] > 0 || $counts['orphans'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
