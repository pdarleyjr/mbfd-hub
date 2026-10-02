<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;

final class TreeService
{
    public function updateNode(ManualNode $node, array $data): void
    {
        foreach (['manual_id', 'edition_id'] as $field) {
            if (isset($data[$field]) && (int) $data[$field] !== $node->{$field}) {
                throw ValidationException::withMessages([$field => 'Navigation entries must remain in their original manual edition.']);
            }
        }
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = ManualNode::query()->findOrFail($parentId);
            if ($parent->type !== 'section') {
                throw ValidationException::withMessages(['parent_id' => 'Choose a section as the parent.']);
            }
            $seen = [$node->id];
            while ($parent !== null) {
                if ($parent->edition_id !== $node->edition_id || in_array($parent->id, $seen, true)) {
                    throw ValidationException::withMessages(['parent_id' => 'Choose a parent in the same edition outside this node’s descendants.']);
                }
                $seen[] = $parent->id;
                $parent = $parent->parent;
            }
        }
        $node->update(Arr::only($data, ['parent_id', 'title', 'short_title', 'slug', 'type', 'sort_order', 'is_active']));
    }

    public function tree(Manual $manual): array
    {
        $nodes = $manual->nodes()->where('edition_id', $manual->active_edition_id)->where('is_active', true)
            ->with(['currentRevision.pages'])->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');
        $documents = [];
        $visit = function (?int $parentId) use (&$visit, $nodes, &$documents): array {
            return ($nodes[$parentId ?? ''] ?? collect())->map(function (ManualNode $node) use (&$visit, &$documents): array {
                $revision = $node->currentRevision;
                if ($revision && $revision->state === 'published') {
                    $documents[] = $node->id;
                }

                return [
                    'id' => $node->id, 'parent_id' => $node->parent_id, 'slug' => $node->slug,
                    'title' => $node->title, 'short_title' => $node->short_title, 'type' => $node->type,
                    'metadata' => Arr::only($node->metadata ?? [], ['asset_id', 'review_edition', 'parent_identity_id']) + ['related_document_slugs' => array_values(array_filter($node->metadata['related_document_slugs'] ?? [], fn ($slug) => is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug)))],
                    'sort_order' => $node->sort_order, 'revision' => $revision && $revision->state === 'published' ? $this->revisionData($revision) : null,
                    'children' => $visit($node->id),
                ];
            })->all();
        };
        $tree = $visit(null);

        return ['manual' => $manual->only(['id', 'slug', 'name', 'type', 'description', 'active_edition_id']), 'nodes' => $tree, 'documents' => $documents];
    }

    public function revisionData(DocumentRevision $revision): array
    {
        $metadata = Arr::only($revision->metadata ?? [], ['asset_id', 'canonical_sha256', 'source_sha256', 'asset_type', 'parent_identity_id', 'review_edition', 'primary_entries', 'subject_aliases', 'peer_links']);
        if (isset($metadata['primary_entries'])) {
            $metadata['primary_entries'] = array_map(fn (array $entry): array => Arr::except($entry, ['semantic_text']), $metadata['primary_entries']);
        }

        return [
            'id' => $revision->uuid, 'version_label' => $revision->version_label,
            'revision_date' => $revision->revision_date?->toDateString(), 'published_at' => $revision->published_at?->toIso8601String(),
            'page_count' => $revision->page_count, 'asset_url' => '/assets/'.$revision->uuid,
            'download_url' => '/assets/'.$revision->uuid.'/download',
            'canonical_url' => $revision->source_path ? '/assets/'.$revision->uuid.'/canonical' : null,
            'metadata' => $metadata,
            'pages' => $revision->pages->map(fn ($page) => $page->only(['page', 'physical_page', 'printed_label', 'title']))->all(),
        ];
    }
}
