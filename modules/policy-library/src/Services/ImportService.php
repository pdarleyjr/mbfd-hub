<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Support\Audit;

final class ImportService
{
    public function __construct(private RevisionService $revisions) {}

    public function stage(array $manifest, string $root, ?int $userId = null): array
    {
        if (! isset($manifest['manuals']) || ! is_array($manifest['manuals']) || $manifest['manuals'] === []) {
            throw ValidationException::withMessages(['manifest' => 'The import manifest must contain at least one manual.']);
        }

        return DB::transaction(function () use ($manifest, $root, $userId): array {
            $editions = [];
            foreach ($manifest['manuals'] as $entry) {
                validator($entry, ['slug' => 'required|alpha_dash|max:100', 'name' => 'required|string|max:255', 'type' => 'required|string|max:50', 'sections' => 'required|array|min:1'])->validate();
                $manual = Manual::query()->firstOrCreate(['slug' => $entry['slug']], [
                    'name' => $entry['name'], 'type' => $entry['type'], 'description' => $entry['description'] ?? null,
                    'sort_order' => $entry['sort_order'] ?? 0, 'is_active' => $entry['is_active'] ?? true,
                ]);
                $edition = $manual->editions()->create([
                    'label' => $entry['version_label'] ?? 'Imported '.now()->toDateString(), 'created_by' => $userId,
                    'metadata' => array_merge($entry['metadata'] ?? [], ['source_manifest_sha256' => hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR)), 'audit' => $manifest['audits'] ?? []]),
                ]);
                foreach ($entry['sections'] as $order => $section) {
                    $this->section($edition, $section, null, $order, $root, $userId);
                }
                if (! $edition->nodes()->whereNotNull('current_revision_id')->exists()) {
                    throw ValidationException::withMessages(['manifest' => 'An imported edition must contain validated documents.']);
                }
                Audit::record('edition.staged', $userId, ['manual_id' => $manual->id], ['edition_id' => $edition->id]);
                $editions[] = $edition;
            }

            return $editions;
        });
    }

    private function section(Edition $edition, array $section, ?int $parentId, int $order, string $root, ?int $userId): void
    {
        validator($section, ['title' => 'required|string|max:255', 'slug' => 'required|alpha_dash|max:255'])->validate();
        $node = $edition->nodes()->create([
            'manual_id' => $edition->manual_id, 'parent_id' => $parentId, 'title' => $section['title'],
            'slug' => $section['slug'], 'type' => 'section', 'sort_order' => $order,
            'is_active' => $section['is_active'] ?? true,
            'metadata' => $section['metadata'] ?? null,
        ]);
        foreach ($section['children'] ?? [] as $position => $child) {
            $this->section($edition, $child, $node->id, $position, $root, $userId);
        }
        foreach ($section['documents'] ?? [] as $position => $document) {
            validator($document, ['title' => 'required|string|max:255', 'slug' => 'required|alpha_dash|max:255', 'asset_path' => 'required|string', 'sha256' => 'required|regex:/^[a-f0-9]{64}$/', 'page_count' => 'required|integer|min:1'])->validate();
            $documentNode = $edition->nodes()->create([
                'manual_id' => $edition->manual_id, 'parent_id' => $node->id, 'title' => $document['title'],
                'slug' => $document['slug'], 'type' => 'document', 'sort_order' => count($section['children'] ?? []) + $position,
                'metadata' => $document['node_metadata'] ?? null,
            ]);
            $metadata = $document['metadata'] ?? [];
            if (! empty($document['source_path'])) {
                $source = $this->sourcePath($root, $document['source_path']);
                $sourceHash = hash_file('sha256', $source);
                if (isset($metadata['canonical_sha256']) && $metadata['canonical_sha256'] !== $sourceHash) {
                    throw ValidationException::withMessages(['manifest' => 'The canonical PDF does not match the import manifest.']);
                }
                $sourceArchive = 'sources/'.$sourceHash.'.pdf';
                $this->revisions->archive($source, $sourceArchive, $sourceHash);
                $metadata['source_archive'] = $sourceArchive;
            }
            $revision = $this->revisions->createDraft($documentNode, $this->sourcePath($root, $document['asset_path']), basename($document['asset_path']), $userId,
                array_merge($metadata, ['expected_sha256' => $document['sha256'], 'expected_page_count' => $document['page_count'], 'version_label' => $document['version_label'] ?? null, 'revision_date' => $document['revision_date'] ?? null]),
                $document['pages'] ?? []);
            // Draft editions are hidden as a whole; pointers are prepared without publishing any member-visible state.
            $documentNode->update(['current_revision_id' => $revision->id]);
        }
    }

    public function publish(Edition $edition, ?int $userId): void
    {
        DB::transaction(function () use ($edition, $userId): void {
            $manual = Manual::query()->lockForUpdate()->findOrFail($edition->manual_id);
            $target = Edition::query()->lockForUpdate()->findOrFail($edition->id);
            $nodes = $target->nodes()->where('type', 'document')->with('currentRevision')->get();
            if ($nodes->isEmpty()) {
                throw ValidationException::withMessages(['edition' => 'This edition has no documents.']);
            }
            foreach ($nodes as $node) {
                if (! $node->currentRevision) {
                    throw ValidationException::withMessages(['edition' => 'Every document needs a validated revision.']);
                }
                $this->revisions->assertStored($node->currentRevision);
                $node->currentRevision->update(['state' => 'published', 'published_by' => $userId, 'published_at' => now()]);
            }
            if ($manual->active_edition_id && $manual->active_edition_id !== $target->id) {
                Edition::query()->whereKey($manual->active_edition_id)->update(['state' => 'archived']);
            }
            $target->update(['state' => 'published', 'published_by' => $userId, 'published_at' => now()]);
            $manual->update(['active_edition_id' => $target->id]);
            Audit::record('edition.published', $userId, ['manual_id' => $manual->id], ['edition_id' => $target->id]);
        });
    }

    public function stageSection(ManualNode $target, array $section, string $root, ?int $userId): Edition
    {
        $manual = $target->manual;
        if ($target->type !== 'section' || $target->edition_id !== $manual->active_edition_id) {
            throw ValidationException::withMessages(['section' => 'Choose a section from the current published edition.']);
        }

        return DB::transaction(function () use ($target, $section, $root, $userId, $manual): Edition {
            $edition = $manual->editions()->create(['label' => 'Section update '.now()->toDateString(), 'created_by' => $userId, 'metadata' => ['replaces_section_id' => $target->id]]);
            $all = $manual->nodes()->where('edition_id', $manual->active_edition_id)->with('currentRevision.pages')->get()->keyBy('id');
            $excluded = [$target->id];
            do {
                $before = count($excluded);
                foreach ($all as $node) {
                    if (in_array($node->parent_id, $excluded, true) && ! in_array($node->id, $excluded, true)) {
                        $excluded[] = $node->id;
                    }
                }
            } while (count($excluded) > $before);
            $copies = [];
            $copy = function (ManualNode $node) use (&$copy, &$copies, $all, $edition, $userId): ManualNode {
                if (isset($copies[$node->id])) {
                    return $copies[$node->id];
                }
                $parent = $node->parent_id ? $copy($all[$node->parent_id]) : null;
                $new = $edition->nodes()->create($node->only(['manual_id', 'type', 'title', 'short_title', 'slug', 'sort_order', 'is_active', 'metadata']) + ['parent_id' => $parent?->id]);
                if ($node->currentRevision) {
                    $revision = $new->revisions()->create($node->currentRevision->only(['version_label', 'revision_date', 'revision_notes', 'source_filename', 'storage_path', 'source_path', 'sha256', 'page_count', 'metadata']) + ['uuid' => (string) Str::uuid(), 'state' => 'draft', 'uploaded_by' => $userId]);
                    foreach ($node->currentRevision->pages as $page) {
                        $revision->pages()->create($page->only(['page', 'physical_page', 'printed_label', 'title', 'text']));
                    }
                    $new->update(['current_revision_id' => $revision->id]);
                }

                return $copies[$node->id] = $new;
            };
            foreach ($all as $node) {
                if (! in_array($node->id, $excluded, true)) {
                    $copy($node);
                }
            }
            $this->section($edition, array_replace($section, $target->only(['title', 'slug', 'is_active'])), $target->parent_id ? $copies[$target->parent_id]->id : null, $target->sort_order, $root, $userId);
            Audit::record('section.staged', $userId, ['manual_id' => $manual->id, 'node_id' => $target->id], ['edition_id' => $edition->id]);

            return $edition;
        });
    }

    private function sourcePath(string $root, string $relative): string
    {
        $base = realpath($root);
        $path = realpath(rtrim($root, '/\\').DIRECTORY_SEPARATOR.$relative);
        if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw ValidationException::withMessages(['manifest' => 'An import file is missing or outside the import directory.']);
        }

        return $path;
    }
}
