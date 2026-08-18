<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestAttachmentService
{
    public function __construct(private readonly PersonnelRequestNotifier $notifier) {}

    public function storeForEmployee(PersonnelRequest $request, Employee $employee, string $documentType, UploadedFile $file): PersonnelRequestAttachment
    {
        if ($request->beneficiary_employee_id !== $employee->id) {
            abort(403);
        }
        if (! $this->canUploadRequestedDocument($request, $documentType)) {
            throw ValidationException::withMessages(['document_type' => 'This document was not requested for the current workflow step.']);
        }

        validator(['attachment' => $file], [
            'attachment' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ])->validate();

        $disk = (string) config('filesystems.private', 'local');
        $publicId = (string) Str::ulid();
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            throw ValidationException::withMessages(['attachment' => 'Only PDF, JPEG, and PNG files are accepted.']);
        }
        $generated = $publicId.'.'.$extension;
        $path = 'personnel-requests/attachments/'.now()->format('Y/m').'/'.$generated;
        $bytes = $file->getContent();
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['attachment' => 'The document could not be stored. Please try again.']);
        }

        try {
            return DB::transaction(function () use ($request, $employee, $documentType, $file, $disk, $path, $generated, $bytes, $publicId): PersonnelRequestAttachment {
                $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ($locked->beneficiary_employee_id !== $employee->id || ! $this->canUploadRequestedDocument($locked, $documentType)) {
                    throw ValidationException::withMessages(['document_type' => 'This document is no longer requested for the current workflow step.']);
                }
                $locked->status = PersonnelRequestStatus::Acknowledged;
                $locked->acknowledged_at ??= now();
                $locked->save();

                $update = $locked->updates()->create([
                    'event' => 'document_uploaded',
                    'status' => PersonnelRequestStatus::Acknowledged,
                    'employee_visible_note' => 'Requested document uploaded: '.$file->getClientOriginalName(),
                    'changed_by_employee_id' => $employee->id,
                    'metadata' => ['document_type' => $documentType],
                ]);

                $attachment = $locked->attachments()->create([
                    'public_id' => $publicId,
                    'personnel_request_update_id' => $update->id,
                    'document_type' => $documentType,
                    'disk' => $disk,
                    'storage_path' => $path,
                    'generated_filename' => $generated,
                    'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                    'file_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'uploaded_by_employee_id' => $employee->id,
                ]);
                DB::afterCommit(fn () => $this->notifier->employeeResponded($locked, 'document'));

                return $attachment;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    private function canUploadRequestedDocument(PersonnelRequest $request, string $documentType): bool
    {
        return in_array($request->status, [PersonnelRequestStatus::NeedsInformation, PersonnelRequestStatus::Acknowledged], true)
            && in_array($documentType, $request->information_requested ?? [], true);
    }
}
