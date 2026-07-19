<?php

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateOperationalFormPdf;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormGeneration;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\FormDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormGenerationController extends Controller
{
    public function __invoke(
        Request $request,
        string $record,
        FormDataValidator $validator,
    ): JsonResponse {
        $owned = OperationalFormRecord::query()
            ->where('employee_id', $request->user('employee')->getKey())
            ->findOrFail($record);

        $validator->validate($owned->form_type, $owned->data, true);
        $generation = OperationalFormGeneration::query()->firstOrCreate(
            ['form_record_id' => $owned->id, 'source_revision' => $owned->revision],
            ['employee_id' => $request->user('employee')->getKey(), 'status' => 'queued'],
        );

        if ($generation->status === 'failed') {
            $generation->update(['status' => 'queued', 'error_message' => null, 'completed_at' => null]);
        }

        if ($generation->status === 'queued') {
            GenerateOperationalFormPdf::dispatch($generation->id);
            $generation->refresh();
        }

        return $this->response($generation, $generation->status === 'completed' ? 200 : 202);
    }

    public function status(Request $request, string $record, string $job): JsonResponse
    {
        $generation = OperationalFormGeneration::query()
            ->where('employee_id', $request->user('employee')->getKey())
            ->where('form_record_id', $record)
            ->findOrFail($job);

        return $this->response($generation, $generation->status === 'completed' ? 200 : 202);
    }

    private function response(OperationalFormGeneration $generation, int $status): JsonResponse
    {
        $generation->loadMissing('document');
        $document = $generation->document;

        return response()->json([
            'job' => [
                'id' => $generation->id,
                'status' => $generation->status,
                'source_revision' => $generation->source_revision,
                'status_url' => route('employee.forms.api.records.generation.status', [
                    'record' => $generation->form_record_id,
                    'job' => $generation->id,
                ]),
                'error_message' => $generation->error_message,
            ],
            'document' => $document ? $this->serializeDocument($document) : null,
        ], $status);
    }

    private function serializeDocument(OperationalFormDocument $document): array
    {
        return [
            'id' => $document->id,
            'version_number' => $document->version_number,
            'display_name' => $document->display_name,
            'page_count' => $document->page_count,
            'pdf_sha256' => $document->pdf_sha256,
            'source_revision' => $document->source_revision,
            'remaining_form_fields' => 0,
            'remaining_annotations' => 0,
            'calculated_totals' => $document->source_snapshot['calculated_totals'] ?? null,
            'preview_url' => route('employee.forms.api.documents.preview', $document),
            'download_url' => route('employee.forms.api.documents.download', $document),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
