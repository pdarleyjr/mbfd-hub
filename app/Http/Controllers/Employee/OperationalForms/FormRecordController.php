<?php

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationalForms\StoreFormRecordRequest;
use App\Http\Requests\OperationalForms\UpdateFormRecordRequest;
use App\Models\Employee;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\FormDataValidator;
use App\Services\OperationalForms\FormRegistry;
use App\Services\OperationalForms\FrocTotalsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FormRecordController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __construct(
        private readonly FormRegistry $registry,
        private readonly FormDataValidator $validator,
    ) {}

    public function formTypes(): JsonResponse
    {
        return response()->json(['form_types' => $this->registry->formTypes()]);
    }

    public function index(Request $request): JsonResponse
    {
        $records = $this->ownedQuery($this->authenticatedEmployee())
            ->with([
                'documents' => fn ($query) => $query->latest('version_number'),
                'imports' => fn ($query) => $query->where('status', 'applied')->latest(),
            ])
            ->latest('updated_at')
            ->get()
            ->map(fn (OperationalFormRecord $record) => $this->serialize($record));

        return response()->json(['records' => $records]);
    }

    public function store(StoreFormRecordRequest $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee();
        $formType = $request->string('form_type')->toString();
        $manifest = $this->registry->get($formType)->manifest();
        $data = $this->defaults($formType, $employee);

        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->getKey(),
            'form_type' => $formType,
            'form_version' => $manifest['form_version'],
            'title' => $request->string('title')->trim()->toString(),
            'status' => 'draft',
            'data' => $data,
            'revision' => 1,
            'last_autosaved_at' => now(),
        ]);

        $this->audit($record, 'created', $request);

        return response()->json(['record' => $this->serialize($record)], 201);
    }

    public function show(Request $request, string $record): JsonResponse
    {
        return response()->json([
            'record' => $this->serialize($this->owned($this->authenticatedEmployee(), $record)->load([
                'documents',
                'imports' => fn ($query) => $query->where('status', 'applied')->latest(),
            ])),
        ]);
    }

    public function documents(Request $request, string $record): JsonResponse
    {
        $owned = $this->owned($this->authenticatedEmployee(), $record);

        return response()->json([
            'documents' => $owned->documents()->latest('version_number')->get()->map(
                fn ($document) => $this->serializeDocument($document),
            ),
        ]);
    }

    public function update(UpdateFormRecordRequest $request, string $record): JsonResponse
    {
        $employee = $this->authenticatedEmployee();
        $owned = $this->owned($employee, $record);
        $data = $this->validator->validate($owned->form_type, $request->validated('data'));
        $now = now();

        $affected = OperationalFormRecord::query()
            ->whereKey($owned->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('revision', $request->integer('revision'))
            ->update([
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'revision' => DB::raw('revision + 1'),
                'status' => $owned->latest_pdf_version === null ? $owned->status : 'draft',
                'last_autosaved_at' => $now,
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            $server = $this->owned($employee, $record);

            return response()->json([
                'code' => 'revision_conflict',
                'message' => 'A newer version of this form has already been saved.',
                'server_revision' => $server->revision,
                'server_data' => $server->data,
                'server_saved_at' => $server->last_autosaved_at?->toIso8601String(),
            ], 409);
        }

        $saved = $this->owned($employee, $record)->load([
            'documents',
            'imports' => fn ($query) => $query->where('status', 'applied')->latest(),
        ]);
        $this->audit($saved, 'autosaved', $request);

        return response()->json(['record' => $this->serialize($saved)]);
    }

    public function destroy(Request $request, string $record): JsonResponse
    {
        $owned = $this->owned($this->authenticatedEmployee(), $record);
        if ($owned->documents()->exists()) {
            return response()->json([
                'message' => 'Records with generated PDFs cannot be deleted.',
            ], 409);
        }
        $this->audit($owned, 'record_deleted', $request);
        $owned->delete();

        return response()->json(status: 204);
    }

    private function owned(Employee $employee, string $id): OperationalFormRecord
    {
        return $this->ownedQuery($employee)->findOrFail($id);
    }

    private function ownedQuery(Employee $employee)
    {
        return OperationalFormRecord::query()->where('employee_id', $employee->getKey());
    }

    private function serialize(OperationalFormRecord $record): array
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
                ? $record->documents->map(fn ($document) => $this->serializeDocument($document))->values()
                : [],
            'import_metadata' => $this->importMetadata($record),
        ];
    }

    private function importMetadata(OperationalFormRecord $record): array
    {
        if (! $record->relationLoaded('imports')) {
            return ['estimated_fields' => [], 'imported_fields' => []];
        }

        return [
            'estimated_fields' => $record->imports->flatMap(
                fn ($import) => data_get($import->result, 'summary.estimated_fields', []),
            )->unique()->values(),
            'imported_fields' => $record->imports->flatMap(function ($import) {
                return array_merge(
                    data_get($import->result, 'summary.applied_fields', []),
                    array_map(fn ($index) => "labor.$index", data_get($import->result, 'summary.appended_labor_rows', [])),
                    array_map(fn ($index) => "vehicle_mileage.$index", data_get($import->result, 'summary.updated_mileage_rows', [])),
                );
            })->unique()->values(),
        ];
    }

    private function serializeDocument($document): array
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

    private function defaults(string $formType, Employee $employee): array
    {
        return match ($formType) {
            'ics_214' => [
                'incident' => [
                    'name' => '', 'date_from' => '', 'time_from' => '', 'date_to' => '', 'time_to' => '',
                ],
                'unit' => [
                    'name' => '', 'ics_position' => '', 'home_agency_unit' => '',
                ],
                'resources' => [],
                'activities' => [],
                'prepared_by' => [
                    'name' => $employee->name,
                    'position_title' => $employee->rank ?? '',
                    'signature_text' => '',
                ],
            ],
            'froc_log_001_ff' => [
                'general_information' => ['department' => 'Miami Beach Fire Department'],
                'team_members' => [[
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->name,
                ]],
                'labor' => [],
                'equipment_hours' => [],
                'vehicle_mileage' => [],
                'materials' => [],
                'certification' => [
                    'page2_employee_signature_text' => '',
                    'page2_reviewer_signature_text' => '',
                    'final_employee_signature_text' => '',
                    'final_employee_signature_date' => '',
                    'final_employee_signature_time' => '',
                    'final_reviewer_signature_text' => '',
                    'final_reviewer_signature_date' => '',
                    'final_reviewer_signature_time' => '',
                    'confirmed' => false,
                ],
                'additional_notes' => [],
                'calculated_totals' => FrocTotalsCalculator::calculate([]),
            ],
        };
    }

    private function audit(OperationalFormRecord $record, string $event, Request $request): void
    {
        OperationalFormEvent::query()->create([
            'form_record_id' => $record->id,
            'employee_id' => $this->authenticatedEmployee()->getKey(),
            'event_type' => $event,
            'request_ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'created_at' => now(),
        ]);
    }
}
