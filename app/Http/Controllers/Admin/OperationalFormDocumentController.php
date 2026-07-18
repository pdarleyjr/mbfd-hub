<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Services\OperationalForms\DocumentStreamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalFormDocumentController extends Controller
{
    public function preview(Request $request, OperationalFormDocument $document, DocumentStreamService $streamer): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $this->audit($request, $document, 'pdf_previewed');

        return $streamer->response($document, false);
    }

    public function download(Request $request, OperationalFormDocument $document, DocumentStreamService $streamer): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $this->audit($request, $document, 'pdf_downloaded');

        return $streamer->response($document, true);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']), 403);
    }

    private function audit(Request $request, OperationalFormDocument $document, string $event): void
    {
        OperationalFormEvent::query()->create([
            'form_record_id' => $document->form_record_id,
            'document_id' => $document->id,
            'user_id' => $request->user()->getKey(),
            'event_type' => $event,
            'request_ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'created_at' => now(),
        ]);
    }
}
