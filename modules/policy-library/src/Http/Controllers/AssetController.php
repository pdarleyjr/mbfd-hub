<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Mbfd\PolicyLibrary\Http\Middleware\ViewerGate;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Mbfd\PolicyLibrary\Support\LibraryAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class AssetController
{
    public function show(string $uuid, RevisionService $storage, TreeService $trees): Response
    {
        return $this->deliver($this->currentRevision($uuid, $trees), $storage, current: true);
    }

    public function download(string $uuid, RevisionService $storage, TreeService $trees): Response
    {
        return $this->deliver($this->currentRevision($uuid, $trees), $storage, attachment: true, current: true);
    }

    public function canonical(string $uuid, RevisionService $storage, TreeService $trees): Response
    {
        return $this->deliver($this->currentRevision($uuid, $trees), $storage, canonical: true, attachment: true, current: true);
    }

    public function adminCanonical(string $uuid, RevisionService $storage, TreeService $trees): Response
    {
        return $this->deliver($this->currentRevision($uuid, $trees, true), $storage, canonical: true, attachment: true, current: true);
    }

    public function currentSog(Request $request, string $assetId, TreeService $trees, ViewerGate $gate): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'regex:/^[1-9][0-9]{0,4}$/', 'integer', 'min:1', 'max:10000']]);
        $node = ManualNode::query()->where('metadata->asset_id', $assetId)->where('is_active', true)
            ->whereHas('manual', fn ($manual) => $manual->whereIn('slug', ['sogs', 'sog-review-controls'])
                ->whereColumn('policy_nodes.edition_id', 'policy_manuals.active_edition_id'))
            ->whereHas('edition', fn ($edition) => $edition->where('state', 'published'))
            ->with(['manual', 'edition', 'currentRevision'])->firstOrFail();
        $revision = $node->currentRevision;
        abort_unless($revision?->state === 'published' && ($revision->metadata['asset_id'] ?? null) === $assetId, 404);
        abort_unless(in_array($node->id, $trees->documentIds($node->manual), true), 404);
        $page = (int) ($data['page'] ?? 1);
        abort_unless($page <= $revision->page_count, 404);

        if ($node->manual->slug === 'sog-review-controls') {
            abort_unless(($node->edition->metadata['admin_only'] ?? false) === true, 404);
            abort_unless(LibraryAccess::canManage($request->user('web')), 403);

            return redirect('/manage/revisions/'.$revision->uuid.'/preview#page='.$page);
        }
        abort_unless($node->manual->is_active, 404);

        return $gate->handle($request, fn () => redirect('/?'.http_build_query(['manual' => 'sogs', 'node' => $node->slug, 'page' => $page])));
    }

    public function preview(string $uuid, RevisionService $storage): Response
    {
        return $this->deliver(DocumentRevision::query()->where('uuid', $uuid)->firstOrFail(), $storage);
    }

    private function currentRevision(string $uuid, TreeService $trees, bool $allowControls = false): DocumentRevision
    {
        $revision = DocumentRevision::query()->where('uuid', $uuid)->with(['node.manual', 'node.edition'])->firstOrFail();
        $node = $revision->node;
        $controls = $allowControls && $node->manual->slug === 'sog-review-controls' && ($node->edition->metadata['admin_only'] ?? false) === true;
        abort_unless(($node->manual->is_active || $controls) && $node->is_active && $revision->state === 'published'
            && $node->edition->state === 'published' && $node->current_revision_id === $revision->id
            && $node->edition_id === $node->manual->active_edition_id, 404);
        abort_unless(in_array($node->id, $trees->documentIds($node->manual), true), 404);

        return $revision;
    }

    private function deliver(DocumentRevision $revision, RevisionService $storage, bool $canonical = false, bool $attachment = false, bool $current = false): Response
    {
        $relative = $canonical ? $revision->source_path : $revision->storage_path;
        abort_unless(is_string($relative) && $relative !== '', 404);
        $path = $storage->path($relative);
        abort_unless(is_file($path), 404);
        $filename = $attachment && isset($revision->metadata['artifact_pdf']) ? basename($revision->metadata['artifact_pdf']) : 'document.pdf';
        $disposition = (new ResponseHeaderBag)->makeDisposition($attachment ? 'attachment' : 'inline', $filename);
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => $disposition,
            'Cache-Control' => $current || isset($revision->metadata['review_edition']) ? 'private, no-store' : 'private, max-age=300, must-revalidate',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
        $accel = config('policy-library.accel_prefix');
        if (is_string($accel) && $accel !== '') {
            return response('', 200, $headers + ['X-Accel-Redirect' => rtrim($accel, '/').'/'.$relative]);
        }

        // Symfony BinaryFileResponse handles Range / If-Range and 206 responses.
        return response()->file($path, $headers)->setPrivate()->setEtag($canonical ? basename($relative, '.pdf') : $revision->sha256);
    }
}
