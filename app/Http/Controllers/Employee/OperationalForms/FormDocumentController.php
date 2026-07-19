<?php

namespace App\Http\Controllers\Employee\OperationalForms;

use App\Http\Controllers\Controller;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Services\OperationalForms\DocumentStreamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormDocumentController extends Controller
{
    public function preview(Request $request, string $document, DocumentStreamService $streamer): StreamedResponse
    {
        $owned = $this->owned($request, $document);
        $this->audit($request, $owned, 'pdf_previewed');

        return $streamer->response($owned, false);
    }

    public function download(Request $request, string $document, DocumentStreamService $streamer): StreamedResponse
    {
        $owned = $this->owned($request, $document);
        $this->audit($request, $owned, 'pdf_downloaded');

        return $streamer->response($owned, true);
    }

    private function owned(Request $request, string $id): OperationalFormDocument
    {
        return OperationalFormDocument::query()
            ->whereHas('record', fn ($query) => $query->where('employee_id', $request->user('employee')->getKey()))
            ->findOrFail($id);
    }

    private function audit(Request $request, OperationalFormDocument $document, string $event): void
    {
        OperationalFormEvent::query()->create([
            'form_record_id' => $document->form_record_id,
            'document_id' => $document->id,
            'employee_id' => $request->user('employee')->getKey(),
            'event_type' => $event,
            'request_ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'created_at' => now(),
        ]);
    }
}
