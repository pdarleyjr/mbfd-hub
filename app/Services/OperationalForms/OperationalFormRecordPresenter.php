<?php

namespace App\Services\OperationalForms;

use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;

final class OperationalFormRecordPresenter
{
    public function present(OperationalFormRecord $record): array
    {
        return [
            'id' => $record->id,
            'form_type' => $record->form_type,
            'form_version' => $record->form_version,
            'title' => $record->title,
            'status' => $record->status,
            'data' => $record->data,
            'revision' => $record->revision,
            'latest_pdf_version' => $record->latest_pdf_version,
            'last_autosaved_at' => $record->last_autosaved_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'has_changes_since_latest_pdf' => $record->has_changes_since_latest_pdf,
            'documents' => $record->relationLoaded('documents')
                ? $record->documents->map(fn (OperationalFormDocument $document) => $this->document($document))->values()
                : [],
            'import_metadata' => $this->importMetadata($record),
        ];
    }

    private function importMetadata(OperationalFormRecord $record): array
    {
        $imports = $record->relationLoaded('imports')
            ? $record->imports
            : $record->imports()->where('status', 'applied')->latest()->get();

        return [
            'estimated_fields' => $imports->flatMap(
                fn ($import) => data_get($import->result, 'summary.estimated_fields', []),
            )->unique()->values(),
            'imported_fields' => $imports->flatMap(function ($import) {
                return array_merge(
                    data_get($import->result, 'summary.applied_fields', []),
                    array_map(fn ($index) => "labor.$index", data_get($import->result, 'summary.appended_labor_rows', [])),
                    array_map(fn ($index) => "vehicle_mileage.$index", data_get($import->result, 'summary.updated_mileage_rows', [])),
                );
            })->unique()->values(),
        ];
    }

    private function document(OperationalFormDocument $document): array
    {
        return [
            'id' => $document->id,
            'version_number' => $document->version_number,
            'source_revision' => $document->source_revision,
            'display_name' => $document->display_name,
            'mime_type' => $document->mime_type,
            'is_inline_previewable' => $document->isInlinePreviewable(),
            'page_count' => $document->page_count,
            'pdf_sha256' => $document->pdf_sha256,
            'preview_url' => route('employee.forms.api.documents.preview', $document),
            'download_url' => route('employee.forms.api.documents.download', $document),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
