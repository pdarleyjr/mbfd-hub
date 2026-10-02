<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Jobs\AnalyzeImport;
use Mbfd\PolicyLibrary\Models\ImportBatch;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;

final class ImportSubmissionService
{
    public function submit(UploadedFile $file, string $kind, ?int $userId, ?int $sectionId = null, ?int $manualId = null): ImportBatch
    {
        if (! in_array($kind, ['moms', 'sog', 'pdf', 'package'], true) || ! $file->isValid() || $file->getSize() > config('policy-library.max_upload_kb') * 1024) {
            throw ValidationException::withMessages(['pdf' => 'Upload a document within the allowed size.']);
        }
        if ($sectionId !== null) {
            $section = ManualNode::query()->findOrFail($sectionId);
            if ($section->type !== 'section' || $section->edition_id !== $section->manual->active_edition_id || $kind === 'moms') {
                throw ValidationException::withMessages(['file' => 'Choose a section from the current published edition.']);
            }
            $manualId = $section->manual_id;
        } elseif ($manualId !== null) {
            Manual::query()->findOrFail($manualId);
        }
        if ($kind === 'pdf' && $manualId === null) {
            throw ValidationException::withMessages(['file' => 'Select the manual to replace.']);
        }
        $uuid = (string) Str::uuid();
        $hash = hash_file('sha256', $file->getRealPath());
        $relative = 'imports/'.$uuid.'/upload.'.($kind === 'package' ? 'zip' : 'pdf');
        $path = rtrim(config('policy-library.storage_root'), '/\\').'/'.$relative;
        if (! mkdir(dirname($path), 0700, true) || ! copy($file->getRealPath(), $path) || hash_file('sha256', $path) !== $hash) {
            throw ValidationException::withMessages(['pdf' => 'The upload could not be saved. Try again.']);
        }
        @chmod($path, 0600);

        return DB::transaction(function () use ($file, $kind, $userId, $sectionId, $manualId, $uuid, $hash, $relative): ImportBatch {
            $batch = ImportBatch::query()->create(['uuid' => $uuid, 'kind' => $kind, 'state' => 'queued', 'source_filename' => basename($file->getClientOriginalName()), 'storage_path' => $relative, 'sha256' => $hash, 'uploaded_by' => $userId, 'section_id' => $sectionId, 'manual_id' => $manualId, 'status_message' => 'Waiting for document analysis.']);
            AnalyzeImport::dispatch($batch->id)->onConnection(config('policy-library.queue_connection'))->onQueue(config('policy-library.queue_name'))->afterCommit();

            return $batch;
        });
    }

    public function retry(ImportBatch $batch): void
    {
        $claimed = ImportBatch::query()->whereKey($batch->id)->where('state', 'failed')->update(['state' => 'queued', 'started_at' => null, 'finished_at' => null, 'status_message' => 'Waiting for document analysis.']);
        if (! $claimed) {
            throw ValidationException::withMessages(['import' => 'Only failed imports can be retried.']);
        }
        AnalyzeImport::dispatch($batch->id)->onConnection(config('policy-library.queue_connection'))->onQueue(config('policy-library.queue_name'));
    }
}
