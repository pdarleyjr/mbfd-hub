<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OperationalFormDeletionController extends Controller
{
    public function record(
        Request $request,
        string $record,
        OperationalFormDeletionService $deletion,
    ): Response|RedirectResponse {
        $this->authorizeAdmin($request);
        $deletion->deleteRecord(OperationalFormRecord::query()->findOrFail($record));

        return response()->noContent();
    }

    public function document(
        Request $request,
        string $document,
        OperationalFormDeletionService $deletion,
    ): Response {
        $this->authorizeAdmin($request);
        $deletion->deleteDocument(
            OperationalFormDocument::query()->findOrFail($document),
            $request->user(),
            $request->ip(),
        );

        if ($request->boolean('redirect')) {
            return redirect()->back()->with('status', 'The document was deleted.');
        }

        return response()->noContent();
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']), 403);
    }
}
