<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Support\Audit;

final class RevisionService
{
    public function __construct(private PdfService $pdf) {}

    public function createDraft(ManualNode $node, string $path, string $filename, ?int $userId, array $metadata = [], array $pages = []): DocumentRevision
    {
        $inspection = $this->pdf->inspect($path);
        if (($metadata['expected_sha256'] ?? $inspection['sha256']) !== $inspection['sha256']
            || (int) ($metadata['expected_page_count'] ?? $inspection['page_count']) !== $inspection['page_count']) {
            throw ValidationException::withMessages(['pdf' => 'The PDF does not match the import manifest.']);
        }
        if ($pages !== [] && count($pages) !== $inspection['page_count']) {
            throw ValidationException::withMessages(['pages' => 'Every PDF page must have exactly one metadata entry.']);
        }
        $relativePath = 'revisions/'.$inspection['sha256'].'.pdf';
        $this->archive($path, $relativePath, $inspection['sha256']);
        if (! isset($metadata['source_archive'])) {
            $metadata['source_archive'] = 'sources/'.$inspection['sha256'].'.pdf';
            $this->archive($path, $metadata['source_archive'], $inspection['sha256']);
        }
        $textPages = $pages === [] ? $this->pdf->pageText($path) : [];

        return DB::transaction(function () use ($node, $filename, $userId, $metadata, $pages, $inspection, $relativePath, $textPages): DocumentRevision {
            $revision = $node->revisions()->create([
                'uuid' => (string) Str::uuid(), 'source_filename' => basename($filename),
                'storage_path' => $relativePath, 'source_path' => $metadata['source_archive'] ?? null,
                'sha256' => $inspection['sha256'], 'page_count' => $inspection['page_count'],
                'version_label' => $metadata['version_label'] ?? null, 'revision_date' => $metadata['revision_date'] ?? null,
                'revision_notes' => $metadata['revision_notes'] ?? null,
                'uploaded_by' => $userId, 'state' => 'draft', 'metadata' => $metadata,
            ]);
            for ($page = 1; $page <= $inspection['page_count']; $page++) {
                $entry = $pages[$page - 1] ?? [];
                $revision->pages()->create([
                    'page' => $page, 'physical_page' => $entry['physical_page'] ?? null,
                    'printed_label' => isset($entry['printed_label']) ? (string) $entry['printed_label'] : null,
                    'title' => $entry['title'] ?? $node->title, 'text' => $entry['text'] ?? ($textPages[$page - 1] ?? null),
                ]);
            }
            Audit::record('revision.drafted', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id, 'revision_id' => $revision->id]);

            return $revision;
        });
    }

    public function publish(DocumentRevision $revision, ?int $userId): void
    {
        DB::transaction(function () use ($revision, $userId): void {
            $node = ManualNode::query()->lockForUpdate()->findOrFail($revision->node_id);
            $target = DocumentRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertStored($target);
            if ($node->current_revision_id === $target->id) {
                return;
            }
            $old = $node->current_revision_id;
            $oldState = $target->state;
            if ($old) {
                DocumentRevision::query()->whereKey($old)->update(['state' => 'archived']);
            }
            $target->update(['state' => 'published', 'published_by' => $userId, 'published_at' => now()]);
            $node->update(['current_revision_id' => $target->id]);
            Audit::record($oldState === 'archived' ? 'revision.rollback' : 'revision.published', $userId,
                ['manual_id' => $node->manual_id, 'node_id' => $node->id, 'revision_id' => $target->id], ['previous_revision_id' => $old]);
        });
    }

    public function replaceRange(DocumentRevision $current, int $start, int $end, string $replacement, string $filename, ?int $userId): DocumentRevision
    {
        $temporary = tempnam(sys_get_temp_dir(), 'mbfd-policy-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate PDF processing storage.');
        }
        try {
            $this->pdf->replaceRange($this->path($current->storage_path), $start, $end, $replacement, $temporary);
            $incoming = $this->pdf->inspect($replacement);
            $sourceArchive = 'sources/'.$incoming['sha256'].'.pdf';
            $this->archive($replacement, $sourceArchive, $incoming['sha256']);
            $text = $this->pdf->pageText($temporary);
            $oldPages = $current->pages->keyBy('page');
            $pages = [];
            $count = $current->page_count - ($end - $start + 1) + $incoming['page_count'];
            for ($page = 1; $page <= $count; $page++) {
                $oldPage = $page < $start ? $page : ($page >= $start + $incoming['page_count'] ? $page - $incoming['page_count'] + ($end - $start + 1) : null);
                $entry = $oldPage !== null && isset($oldPages[$oldPage]) ? $oldPages[$oldPage]->only(['physical_page', 'printed_label', 'title']) : ['title' => $current->node->title];
                $pages[] = $entry + ['text' => $text[$page - 1] ?? null];
            }

            return $this->createDraft($current->node, $temporary, $filename, $userId, [
                'revision_notes' => 'Replaces pages '.$start.'–'.$end.' of revision '.$current->uuid,
                'replaces_revision' => $current->uuid, 'replaced_range' => [$start, $end],
                'source_archive' => $sourceArchive,
            ], $pages);
        } finally {
            @unlink($temporary);
        }
    }

    public function archive(string $source, string $relativePath, string $hash): void
    {
        $destination = $this->path($relativePath);
        if (is_file($destination)) {
            if (hash_file('sha256', $destination) !== $hash) {
                throw new \RuntimeException('Immutable storage checksum mismatch.');
            }

            return;
        }
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
            throw new \RuntimeException('Unable to create private document storage.');
        }
        $stream = fopen($destination, 'xb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create immutable document.');
        }
        try {
            $input = fopen($source, 'rb');
            if ($input === false) {
                throw new \RuntimeException('Unable to read source document.');
            }
            try {
                stream_copy_to_stream($input, $stream);
            } finally {
                fclose($input);
            }
        } finally {
            fclose($stream);
        }
        if (hash_file('sha256', $destination) !== $hash) {
            @unlink($destination);
            throw new \RuntimeException('Document copy failed checksum validation.');
        }
        @chmod($destination, 0600);
    }

    public function path(string $relativePath): string
    {
        if (! preg_match('#^(revisions|sources)/[a-f0-9]{64}\.pdf$#', $relativePath)) {
            throw new \RuntimeException('Invalid private document path.');
        }

        return rtrim(config('policy-library.storage_root'), '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function assertStored(DocumentRevision $revision): void
    {
        $path = $this->path($revision->storage_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $revision->sha256 || $revision->pages()->count() !== $revision->page_count) {
            throw ValidationException::withMessages(['pdf' => 'This revision failed integrity validation and cannot be published.']);
        }
    }
}
