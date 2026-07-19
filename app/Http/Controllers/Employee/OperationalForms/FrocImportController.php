<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OperationalForms\FrocImportService;
use App\Services\OperationalForms\FrocRecordImportService;
use App\Services\OperationalForms\OperationalFormRecordPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FrocImportController extends Controller
{
    public function __invoke(Request $request, FrocImportService $service): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN .\/_-]*$/u'],
            'notes' => ['nullable', 'string', 'max:524288', 'required_without:notes_file'],
            'notes_file' => ['nullable', 'file', 'max:2048', 'required_without:notes'],
        ]);

        try {
            $preview = $service->preview(
                trim($validated['unit_id']),
                $validated['notes'] ?? null,
                $request->file('notes_file'),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['import' => $exception->getMessage()]);
        }

        return response()->json(['preview' => $preview]);
    }

    public function apply(
        Request $request,
        string $record,
        FrocRecordImportService $service,
        OperationalFormRecordPresenter $presenter,
    ): JsonResponse {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'unit_id' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN .\/_-]*$/u'],
            'notes' => ['nullable', 'string', 'max:524288', 'required_without:notes_file'],
            'notes_file' => ['nullable', 'file', 'max:2048', 'required_without:notes'],
            'merge_mode' => ['required', 'in:fill_empty_and_append'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        /** @var Employee $employee */
        $employee = $request->user('employee');

        try {
            $result = $service->apply(
                $employee,
                $record,
                (int) $validated['revision'],
                trim($validated['unit_id']),
                $validated['notes'] ?? null,
                $request->file('notes_file'),
                $validated['idempotency_key'],
            );
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['import' => $exception->getMessage()]);
        }

        if ($result['conflict']) {
            return response()->json([
                'code' => 'revision_conflict',
                'message' => 'The form changed while the activity notes were analyzed. No imported fields were applied.',
                'server_revision' => $result['server_revision'],
                'import' => $result['import'],
            ], 409);
        }

        return response()->json([
            'record' => $presenter->present($result['record']),
            'import' => $result['import'],
        ]);
    }

    public function undo(
        Request $request,
        string $record,
        string $import,
        FrocRecordImportService $service,
        OperationalFormRecordPresenter $presenter,
    ): JsonResponse {
        $validated = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        /** @var Employee $employee */
        $employee = $request->user('employee');
        $restored = $service->undo($employee, $record, $import, (int) $validated['revision']);

        return response()->json(['record' => $presenter->present($restored)]);
    }
}
