<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Symfony\Component\HttpFoundation\Response;

final class AssetController
{
    public function show(string $uuid, RevisionService $storage, TreeService $trees): Response
    {
        $revision = DocumentRevision::query()->where('uuid', $uuid)->with('node.manual')->firstOrFail();
        $node = $revision->node;
        abort_unless($node->manual->is_active && $node->is_active && $revision->state === 'published'
            && $node->current_revision_id === $revision->id && $node->edition_id === $node->manual->active_edition_id, 404);
        abort_unless(in_array($node->id, $trees->tree($node->manual)['documents'], true), 404);

        return $this->deliver($revision, $storage);
    }

    public function preview(string $uuid, RevisionService $storage): Response
    {
        return $this->deliver(DocumentRevision::query()->where('uuid', $uuid)->firstOrFail(), $storage);
    }

    private function deliver(DocumentRevision $revision, RevisionService $storage): Response
    {
        $path = $storage->path($revision->storage_path);
        abort_unless(is_file($path), 404);
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="document.pdf"', 'Cache-Control' => 'private, max-age=300, must-revalidate', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
        $accel = config('policy-library.accel_prefix');
        if (is_string($accel) && $accel !== '') {
            return response('', 200, $headers + ['X-Accel-Redirect' => rtrim($accel, '/').'/'.$revision->storage_path]);
        }

        // Symfony BinaryFileResponse handles Range / If-Range and 206 responses.
        return response()->file($path, $headers)->setEtag($revision->sha256);
    }
}
