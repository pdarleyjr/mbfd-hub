<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OperationalFormDeletionController extends Controller
{
    public function record(
        Request $request,
        string $record,
        OperationalFormDeletionService $deletion,
    ): Response {
        $this->authorizeAdmin($request);
        $deletion->deleteRecord(OperationalFormRecord::query()->findOrFail($record), $request->user());

        return response()->noContent();
    }

    public function document(
        Request $request,
        string $document,
        OperationalFormDeletionService $deletion,
    ): never {
        $this->authorizeAdmin($request);
        $deletion->deleteDocument(
            OperationalFormDocument::query()->findOrFail($document),
            $request->user(),
            $request->ip(),
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->isAuthenticationAllowed() && $user->hasCurrentAdminPanelEntitlement()
            && ($user->hasRole('super_admin') || $user->can('admin.forms.manage')), 403);
    }
}
