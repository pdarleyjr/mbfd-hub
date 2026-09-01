<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestAttachment;
use App\Services\PersonnelRequests\PersonnelRequestAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonnelRequestAttachmentController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function store(Request $request, PersonnelRequest $personnelRequest, PersonnelRequestAttachmentService $attachments): RedirectResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'attachment' => ['required', 'file'],
        ]);
        $employee = $this->authenticatedEmployee();
        $attachments->storeForEmployee($personnelRequest, $employee, $validated['document_type'], $validated['attachment']);

        return back()->with('status', 'Document uploaded securely.');
    }

    public function download(PersonnelRequestAttachment $attachment): StreamedResponse
    {
        $employee = $this->authenticatedEmployee();
        if ($attachment->request()->where('beneficiary_employee_id', $employee->id)->doesntExist()) {
            abort(403);
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return response()->streamDownload(
            fn () => print Storage::disk($attachment->disk)->get($attachment->storage_path),
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
