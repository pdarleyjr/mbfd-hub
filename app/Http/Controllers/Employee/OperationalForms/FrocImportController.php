<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Http\Controllers\Controller;
use App\Services\OperationalForms\FrocImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
