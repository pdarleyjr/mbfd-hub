<?php

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationalForms\StoreUploadedFormRequest;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\OperationalFormRecordPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FormUploadController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        StoreUploadedFormRequest $request,
        OperationalFormRecordPresenter $presenter,
    ): JsonResponse {
        $employee = $this->authenticatedEmployee();
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $title = $this->safeTitle($request->string('name')->toString());
        $extension = $this->safeExtension($file->getClientOriginalExtension());
        $displayName = $this->displayName($title, $extension);
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $sha256 = hash_file('sha256', $file->getRealPath());
        if ($sha256 === false) {
            throw new RuntimeException('The uploaded file could not be checksummed.');
        }

        $recordId = (string) Str::ulid();
        $documentId = (string) Str::ulid();
        $disk = config('filesystems.private', 'local');
        $storageName = $documentId.($extension !== '' ? '.'.$extension : '');
        $storageDirectory = sprintf(
            'operational-form-uploads/%s/%s/%s',
            now()->format('Y/m'),
            $employee->getKey(),
            $recordId,
        );
        $storedPath = null;

        try {
            $storedPath = Storage::disk($disk)->putFileAs(
                $storageDirectory,
                $file,
                $storageName,
                ['visibility' => 'private'],
            );
            if (! is_string($storedPath) || $storedPath === '') {
                throw new RuntimeException('Unable to store the submitted file on the private filesystem.');
            }

            $record = DB::transaction(function () use (
                $request,
                $employee,
                $title,
                $displayName,
                $mimeType,
                $sha256,
                $file,
                $recordId,
                $documentId,
                $disk,
                $storedPath,
            ): OperationalFormRecord {
                $now = now();
                $snapshot = [
                    'upload' => [
                        'submitted_name' => $title,
                        'original_name' => $file->getClientOriginalName(),
                        'display_name' => $displayName,
                        'mime_type' => $mimeType,
                        'file_size' => $file->getSize(),
                        'sha256' => $sha256,
                    ],
                ];

                $record = OperationalFormRecord::query()->create([
                    'id' => $recordId,
                    'employee_id' => $employee->getKey(),
                    'form_type' => 'uploaded_file',
                    'form_version' => '1',
                    'title' => $title,
                    'status' => 'completed',
                    'data' => $snapshot,
                    'revision' => 1,
                    'latest_pdf_version' => 1,
                    'last_autosaved_at' => $now,
                    'completed_at' => $now,
                ]);

                $document = OperationalFormDocument::query()->create([
                    'id' => $documentId,
                    'form_record_id' => $record->id,
                    'version_number' => 1,
                    'source_revision' => 1,
                    'storage_disk' => $disk,
                    'storage_path' => $storedPath,
                    'display_name' => $displayName,
                    'mime_type' => $mimeType,
                    'file_size' => $file->getSize(),
                    'page_count' => 0,
                    'pdf_sha256' => $sha256,
                    'source_snapshot' => $snapshot,
                    'template_version' => 'uploaded',
                    'template_sha256' => str_repeat('0', 64),
                    'mapping_sha256' => str_repeat('0', 64),
                    'generator_version' => 'uploaded',
                    'created_by_employee_id' => $employee->getKey(),
                ]);

                OperationalFormEvent::query()->create([
                    'form_record_id' => $record->id,
                    'document_id' => $document->id,
                    'employee_id' => $employee->getKey(),
                    'event_type' => 'file_uploaded',
                    'request_ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
                    'created_at' => $now,
                ]);

                return $record->load(['documents', 'imports']);
            });

            return response()->json(['record' => $presenter->present($record)], 201);
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk($disk)->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function safeTitle(string $title): string
    {
        $title = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', ' ', trim($title)) ?: 'Submitted file';

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $title) ?: $title), 200, '');
    }

    private function safeExtension(string $extension): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: '');
    }

    private function displayName(string $title, string $extension): string
    {
        if ($extension === '' || str_ends_with(strtolower($title), '.'.$extension)) {
            return $title;
        }

        return Str::limit($title, 230, '').'.'.$extension;
    }
}
