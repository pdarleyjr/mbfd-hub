<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResourceDocument;
use App\Models\ResourceDocumentVersion;
use App\Services\ResourceLibrary\ResourceDocumentStreamer;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Resource Library: download a file a document had before it was replaced. Managers only
 * (admin.resources.manage or super admin); the viewer capability can read the history but not the old bytes.
 */
final class ResourceDocumentVersionController extends Controller
{
    public function __construct(private readonly ResourceDocumentStreamer $streamer) {}

    public function download(string $document, int $version): StreamedResponse
    {
        Gate::authorize('update', ResourceDocument::class);

        $owner = ResourceDocument::query()->select(['id', 'uuid'])->where('uuid', $document)->firstOrFail();
        $row = ResourceDocumentVersion::query()
            ->where('resource_document_id', $owner->id)
            ->where('version', $version)
            ->firstOrFail();

        return $this->streamer->downloadVersion($row);
    }
}
