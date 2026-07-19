<?php

namespace App\Services\OperationalForms;

use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class OperationalFormDeletionService
{
    public function deleteRecord(OperationalFormRecord $record): void
    {
        $files = $record->documents()
            ->get(['storage_disk', 'storage_path'])
            ->map(fn (OperationalFormDocument $document) => [
                'disk' => $document->storage_disk,
                'path' => $document->storage_path,
            ])
            ->all();

        DB::transaction(function () use ($record): void {
            $record->forceDelete();
        });

        foreach ($files as $file) {
            $this->deleteStoredFile($file['disk'], $file['path']);
        }
    }

    public function deleteDocument(OperationalFormDocument $document, User $admin, ?string $requestIp = null): void
    {
        $disk = $document->storage_disk;
        $path = $document->storage_path;

        DB::transaction(function () use ($document, $admin, $requestIp): void {
            $record = OperationalFormRecord::query()->lockForUpdate()->findOrFail($document->form_record_id);

            OperationalFormEvent::query()->create([
                'form_record_id' => $record->id,
                'document_id' => $document->id,
                'user_id' => $admin->getKey(),
                'event_type' => 'document_deleted',
                'request_ip_hash' => $requestIp ? hash('sha256', $requestIp) : null,
                'created_at' => now(),
            ]);

            $document->delete();
            $latest = $record->documents()->latest('version_number')->first();

            $record->update([
                'latest_pdf_version' => $latest?->version_number,
                'status' => $latest && $latest->source_revision === $record->revision ? 'completed' : 'draft',
                'completed_at' => $latest && $latest->source_revision === $record->revision
                    ? ($record->completed_at ?? $latest->created_at)
                    : null,
            ]);
        });

        $this->deleteStoredFile($disk, $path);
    }

    private function deleteStoredFile(string $disk, string $path): void
    {
        if (! Storage::disk($disk)->exists($path)) {
            return;
        }

        if (! Storage::disk($disk)->delete($path)) {
            Log::warning('Unable to remove deleted operational-form file.', [
                'disk' => $disk,
                'path_sha256' => hash('sha256', $path),
            ]);
        }
    }
}
