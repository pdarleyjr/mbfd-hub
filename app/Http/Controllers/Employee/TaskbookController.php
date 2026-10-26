<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ResourceDocument;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\ResourceLibrary\ResourceDocumentStreamer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaskbookController extends Controller
{
    public function __construct(
        private readonly ResourceDocumentStreamer $streamer,
        private readonly AuthenticatedMemberContextResolver $members,
    ) {}

    public function preview(string $document): StreamedResponse
    {
        return $this->streamer->preview($this->personalDocument($document));
    }

    public function download(string $document): StreamedResponse
    {
        return $this->streamer->download($this->personalDocument($document));
    }

    private function personalDocument(string $uuid): ResourceDocument
    {
        $employee = $this->members->resolve(request())->actor()->requireEmployee();

        return ResourceDocument::query()
            ->select(['id', 'uuid', 'original_filename', 'original_mime', 'original_size', 'original_path', 'preview_path'])
            ->taskbooksFor((int) $employee->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
