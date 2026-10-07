<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Mbfd\PolicyLibrary\Models\DocumentRevision;

final class ReadingViewService
{
    public static function available(DocumentRevision $revision): bool
    {
        $reading = $revision->metadata['reading_view'] ?? null;

        return is_array($reading) && ($reading['validated'] ?? false) === true
            && is_string($reading['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $reading['sha256']) === 1
            && is_string($revision->metadata['source_docx_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $revision->metadata['source_docx_sha256']) === 1;
    }

    /** The optional artifact is immutable, private and bound to the served PDF. */
    public function load(DocumentRevision $revision): array
    {
        abort_unless(self::available($revision), 404);
        $hash = $revision->metadata['reading_view']['sha256'];
        $root = realpath((string) config('policy-library.storage_root'));
        abort_unless(is_string($root), 404);
        $directory = $root.DIRECTORY_SEPARATOR.'reading'.DIRECTORY_SEPARATOR;
        $path = realpath($directory.$hash.'.json');
        abort_unless(is_string($path) && str_starts_with($path, $directory), 404);
        abort_unless(is_file($path) && filesize($path) <= 2 * 1024 * 1024, 404);
        $bytes = file_get_contents($path);
        abort_unless(is_string($bytes) && hash_equals($hash, hash('sha256', $bytes)), 404);
        $data = json_decode($bytes, true, 64);
        abort_unless(is_array($data) && ($data['schema'] ?? null) === 'mbfd-reading-v1'
            && ($data['pdf_sha256'] ?? null) === $revision->sha256
            && ($data['source_docx_sha256'] ?? null) === $revision->metadata['source_docx_sha256']
            && ($data['page_count'] ?? null) === $revision->page_count
            && is_array($data['entries'] ?? null) && count($data['entries']) <= 200, 404);
        $entries = [];
        $cover = $data['cover'] ?? [];
        $coverPage = $data['cover_pdf_page'] ?? null;
        abort_unless(is_array($cover) && count($cover) <= 32, 404);
        foreach ($cover as $line) {
            abort_unless(is_string($line) && mb_strlen($line) <= 1000, 404);
        }
        abort_unless($cover === [] || (is_int($coverPage) && $coverPage >= 1 && $coverPage <= $revision->page_count), 404);
        $ids = [];
        $owners = collect($revision->metadata['primary_entries'] ?? [])->keyBy('id');
        $assetId = $revision->metadata['asset_id'] ?? null;
        foreach ($data['entries'] as $entry) {
            abort_unless(is_array($entry) && is_string($entry['id'] ?? null) && ! isset($ids[$entry['id']])
                && is_string($entry['title'] ?? null) && is_array($entry['blocks'] ?? null) && count($entry['blocks']) <= 2000, 404);
            abort_unless($entry['id'] === $assetId || $owners->has($entry['id']), 404);
            $owner = $owners->get($entry['id']);
            $ids[$entry['id']] = true;
            $blocks = [];
            foreach ($entry['blocks'] as $block) {
                abort_unless(is_array($block) && in_array($block['type'] ?? null, ['paragraph', 'heading', 'pdf_reference', 'table'], true)
                    && is_string($block['text'] ?? null) && mb_strlen($block['text']) <= 30000
                    && is_int($block['pdf_page'] ?? null) && $block['pdf_page'] >= 1 && $block['pdf_page'] <= $revision->page_count, 404);
                if (is_array($owner)) {
                    abort_unless(in_array($block['pdf_page'], $owner['semantic_pages'] ?? [$owner['physical_page']], true), 404);
                }
                $output = ['type' => $block['type'], 'text' => $block['text'], 'pdf_page' => $block['pdf_page']];
                if ($block['type'] === 'table') {
                    $output += $this->table($block, $revision->page_count, is_array($owner) ? ($owner['semantic_pages'] ?? [$owner['physical_page']]) : null);
                }
                $blocks[] = $output;
            }
            $entries[] = ['id' => $entry['id'], 'title' => $entry['title'], 'blocks' => $blocks];
        }

        // No host paths, HTML, credentials, or embedded source instructions enter the client.
        return ['revision_id' => $revision->uuid, 'pdf_sha256' => $revision->sha256,
            'source_docx_sha256' => $data['source_docx_sha256'], 'cover' => $cover, 'cover_pdf_page' => $coverPage, 'entries' => $entries];
    }

    private function table(array $block, int $pageCount, ?array $owned): array
    {
        $columns = $block['columns'] ?? null;
        $rows = $block['rows'] ?? null;
        abort_unless(is_int($columns) && $columns >= 1 && $columns <= 20 && is_array($rows) && count($rows) >= 1 && count($rows) <= 200, 404);
        $occupied = array_fill(0, $columns, 0);
        $output = [];
        foreach ($rows as $row) {
            abort_unless(is_array($row) && count($row) <= $columns, 404);
            $column = 0;
            $cells = [];
            foreach ($row as $cell) {
                while ($column < $columns && $occupied[$column] > 0) {
                    $column++;
                }
                abort_unless(is_array($cell) && is_string($cell['text'] ?? null) && mb_strlen($cell['text']) <= 30000
                    && is_int($cell['col_span'] ?? null) && $cell['col_span'] >= 1 && $cell['col_span'] <= $columns
                    && is_int($cell['row_span'] ?? null) && $cell['row_span'] >= 1 && $cell['row_span'] <= count($rows)
                    && is_bool($cell['header'] ?? null) && is_int($cell['pdf_page'] ?? null) && $cell['pdf_page'] >= 1 && $cell['pdf_page'] <= $pageCount
                    && ($owned === null || in_array($cell['pdf_page'], $owned, true)) && $column + $cell['col_span'] <= $columns, 404);
                for ($offset = 0; $offset < $cell['col_span']; $offset++) {
                    abort_unless($occupied[$column + $offset] === 0, 404);
                    $occupied[$column + $offset] = $cell['row_span'];
                }
                $column += $cell['col_span'];
                $cells[] = array_intersect_key($cell, array_flip(['text', 'col_span', 'row_span', 'header', 'pdf_page']));
            }
            abort_unless(! in_array(0, $occupied, true), 404);
            $occupied = array_map(fn (int $span): int => $span - 1, $occupied);
            $output[] = $cells;
        }
        abort_unless(array_sum($occupied) === 0, 404);

        return ['columns' => $columns, 'rows' => $output];
    }
}
