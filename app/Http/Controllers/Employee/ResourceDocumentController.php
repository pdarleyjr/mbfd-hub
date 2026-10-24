<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceDocumentStreamer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves Employee > Resources files. Access is the Employee panel gate (EnsureEmployeeAuthenticated on the
 * routes); only published, undeleted documents resolve, everything else is a 404.
 */
final class ResourceDocumentController extends Controller
{
    public function __construct(private readonly ResourceDocumentStreamer $streamer) {}

    public function preview(string $document): StreamedResponse
    {
        return $this->streamer->preview($this->publishedDocument($document));
    }

    public function download(string $document): StreamedResponse
    {
        return $this->streamer->download($this->publishedDocument($document));
    }

    private function publishedDocument(string $uuid): ResourceDocument
    {
        // The extracted search text can be megabytes and is never needed to serve a file.
        return ResourceDocument::query()
            ->select(['id', 'uuid', 'slug', 'title', 'original_filename', 'original_mime', 'original_size', 'original_path', 'preview_path', 'published'])
            ->published()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
