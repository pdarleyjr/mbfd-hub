<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Employee;
use App\Models\Ems\InventoryRequest;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The member's Files copy of a request: ONE completed OperationalFormRecord
 * (form_type medical_inventory_request) owned by the submitting employee, holding ONE PDF of the
 * immutable snapshot on the private disk. It is read-only: it is never editable or generatable
 * through the ICS / F-ROC paths. Regeneration only restores a missing record or file; the content
 * always comes from the snapshot.
 */
final class MemberCopyService
{
    public const FORM_TYPE = 'medical_inventory_request';

    public const FORM_VERSION = '1';

    public function __construct(private readonly RequestPdfRenderer $renderer) {}

    public static function isReadOnlyType(string $formType): bool
    {
        return $formType === self::FORM_TYPE;
    }

    public function title(InventoryRequest $request): string
    {
        return "Medical Inventory Request {$request->number} · {$request->target_label}";
    }

    /** Whether the record and its file are both in place. */
    public function isComplete(InventoryRequest $request): bool
    {
        if ($request->operational_form_record_id === null) {
            return false;
        }
        $document = OperationalFormDocument::query()->where('form_record_id', $request->operational_form_record_id)->orderByDesc('version_number')->first();

        return $document !== null && Storage::disk($document->storage_disk)->exists($document->storage_path);
    }

    /** Create (or repair) the record and document. Safe to call repeatedly. */
    public function ensure(InventoryRequest $request): ?OperationalFormRecord
    {
        $request = $request->fresh();
        $employee = $request->submitted_by_employee_id !== null ? Employee::query()->find($request->submitted_by_employee_id) : null;
        if ($employee === null) {
            return null; // the member no longer has a personnel profile; nothing to file against
        }

        $record = $request->operational_form_record_id !== null ? OperationalFormRecord::query()->find($request->operational_form_record_id) : null;
        $document = $record?->documents()->orderByDesc('version_number')->first();
        if ($record !== null && $document !== null && Storage::disk($document->storage_disk)->exists($document->storage_path)) {
            return $record;
        }

        $disk = (string) config('filesystems.private', 'local');
        $pdf = $this->renderer->render($request);
        $bytes = $pdf->output();
        $pages = (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
        // Lower-case ULIDs, the same form Eloquent's HasUlids generates, so foreign keys always match.
        $recordId = $record?->id ?? strtolower((string) Str::ulid());
        $documentId = $document?->id ?? strtolower((string) Str::ulid());
        $path = sprintf('operational-form-uploads/ems-requests/%s/%s/%s/%s.pdf', now()->format('Y/m'), $employee->getKey(), $recordId, $documentId);
        $displayName = Str::limit(preg_replace('~[\x00-\x1f\x7f"\\\\/]+~', '_', "{$request->number} {$request->target_label}") ?: $request->number, 200, '').'.pdf';

        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);
        try {
            DB::transaction(function () use ($request, $employee, $record, $document, $recordId, $documentId, $disk, $path, $bytes, $pages, $displayName): void {
                $now = now();
                $summary = [
                    'medical_inventory_request' => [
                        'request_id' => $request->uuid, 'number' => $request->number, 'target_label' => $request->target_label,
                        'submitted_at' => $request->submitted_at?->toIso8601String(), 'line_count' => count((array) ($request->snapshot['lines'] ?? [])),
                        'snapshot_sha256' => $request->snapshot_sha256,
                    ],
                ];
                if ($record === null) {
                    $record = (new OperationalFormRecord)->forceFill([
                        'id' => $recordId, 'employee_id' => $employee->getKey(), 'form_type' => self::FORM_TYPE, 'form_version' => self::FORM_VERSION,
                        'title' => $this->title($request), 'status' => 'completed', 'data' => $summary, 'revision' => 1,
                        'latest_pdf_version' => 1, 'last_autosaved_at' => $now, 'completed_at' => $request->submitted_at ?? $now,
                    ]);
                    $record->save();
                }
                $attributes = [
                    'storage_disk' => $disk, 'storage_path' => $path, 'display_name' => $displayName, 'mime_type' => 'application/pdf',
                    'file_size' => strlen($bytes), 'page_count' => $pages, 'pdf_sha256' => hash('sha256', $bytes),
                    'source_snapshot' => $request->snapshot, 'template_version' => 'ems-mir-1',
                    'template_sha256' => hash('sha256', (string) @file_get_contents(resource_path('views/pdf/medical-inventory-request.blade.php'))),
                    'mapping_sha256' => str_repeat('0', 64), 'generator_version' => 'ems-mir-dompdf',
                ];
                if ($document !== null) {
                    $document->update($attributes);
                } else {
                    (new OperationalFormDocument)->forceFill($attributes + [
                        'id' => $documentId, 'form_record_id' => $record->id, 'version_number' => 1, 'source_revision' => 1,
                        'created_by_employee_id' => $employee->getKey(),
                    ])->save();
                }
                OperationalFormEvent::query()->create([
                    'form_record_id' => $record->id, 'document_id' => $documentId, 'employee_id' => $employee->getKey(),
                    'event_type' => $document !== null ? 'file_regenerated' : 'file_generated', 'created_at' => $now,
                ]);
                if ($request->operational_form_record_id !== $record->id) {
                    $request->update(['operational_form_record_id' => $record->id]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException('The Files copy of '.$request->number.' could not be saved: '.$exception->getMessage(), 0, $exception);
        }

        return OperationalFormRecord::query()->find($recordId);
    }
}
