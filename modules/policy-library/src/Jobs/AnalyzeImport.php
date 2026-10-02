<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mbfd\PolicyLibrary\Models\ImportBatch;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ArchiveImportService;
use Mbfd\PolicyLibrary\Services\ManualUploadService;

final class AnalyzeImport implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $batchId)
    {
        $this->timeout = (int) config('policy-library.job_timeout');
    }

    public function handle(): void
    {
        if (! ImportBatch::query()->whereKey($this->batchId)->where('state', 'queued')->update(['state' => 'processing', 'started_at' => now(), 'status_message' => 'Analyzing documents. The published manual remains available.'])) {
            return;
        }
        $batch = ImportBatch::query()->findOrFail($this->batchId);
        try {
            if (! preg_match('#^imports/[a-f0-9-]{36}/upload\.(pdf|zip)$#', $batch->storage_path)) {
                throw new \RuntimeException('Invalid import storage reference.');
            }
            $path = rtrim(config('policy-library.storage_root'), '/\\').'/'.$batch->storage_path;
            if (! is_file($path) || hash_file('sha256', $path) !== $batch->sha256) {
                throw new \RuntimeException('Import upload integrity validation failed.');
            }
            $section = $batch->section_id ? ManualNode::query()->findOrFail($batch->section_id) : null;
            $manual = $batch->manual_id ? Manual::query()->findOrFail($batch->manual_id) : null;
            $uploads = app(ManualUploadService::class);
            $editions = match ($batch->kind) {
                'moms' => $uploads->stageMoms($path, $batch->uploaded_by, $manual),
                'sog' => $uploads->stageSog($path, $batch->source_filename, $batch->uploaded_by, $manual, $section),
                'pdf' => $uploads->stagePdf($path, $batch->source_filename, $batch->uploaded_by, $manual, $section),
                'package' => app(ArchiveImportService::class)->stage($path, $batch->uploaded_by, $section),
                default => throw new \RuntimeException('Unknown library import format.'),
            };
            $batch->update(['state' => 'ready', 'edition_ids' => array_map(fn ($edition) => $edition->id, $editions), 'finished_at' => now(), 'status_message' => 'Draft ready. Review the edition navigation and PDFs, then publish.']);
        } catch (\Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ImportBatch::query()->whereKey($this->batchId)->where('state', '!=', 'ready')->update(['state' => 'failed', 'finished_at' => now(), 'status_message' => 'Document analysis failed. The published manual was preserved. Review the source or retry.']);
        Log::error('policy_library_import_failed', ['batch_id' => $this->batchId, 'exception_class' => $exception ? $exception::class : null]);
    }
}
