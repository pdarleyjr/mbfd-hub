<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

use RuntimeException;
use ZipArchive;

/**
 * Bounded, media-aware reader for WhatsApp export ZIP archives.
 *
 * It inspects archive metadata without extracting files to disk, ignores all
 * non-`.txt` media, and streams only approved text entries in bounded chunks so
 * a media-bearing archive can never load arbitrary bytes into application
 * memory. Every rejected condition throws {@see FrocImportException} with a
 * stable failure code so logs remain free of raw source content.
 *
 * Security model:
 *  - Entry names are normalized for *comparison only*; traversal, absolute,
 *    drive-letter, and URI/scheme paths are never rewritten into apparently
 *    safe relative paths.
 *  - Text is read by entry *index* (ZipArchive::getStreamIndex) when available
 *    so duplicate names cannot cause the wrong entry to be read. When index
 *    streaming is unavailable, duplicate candidate names are rejected as
 *    ambiguous rather than risking the wrong entry.
 *  - Symbolic links are rejected by inspecting external attributes.
 *  - Only `.txt` entries are ever opened; media is never extracted or read.
 */
final class FrocZipReader
{
    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxTextEntries,
        private readonly int $maxExtractedBytes,
        private readonly float $maxCompressionRatio,
        private readonly int $readChunkBytes = 8192,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            FrocImportLimits::maxZipEntries(),
            FrocImportLimits::maxTextEntries(),
            FrocImportLimits::maxExtractedBytes(),
            FrocImportLimits::maxCompressionRatio(),
        );
    }

    public function read(string $path): FrocZipReadResult
    {
        if (! class_exists(ZipArchive::class)) {
            throw new FrocImportException('zip_unavailable', 'ZIP import is not available on this server. Extract the chat and upload its .txt file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new FrocImportException('zip_open_failed', 'The WhatsApp ZIP could not be opened.');
        }

        $supportsIndexStreams = method_exists($zip, 'getStreamIndex');

        try {
            $total = $zip->numFiles;
            if ($total < 0 || $total > $this->maxEntries) {
                throw new FrocImportException('zip_too_many_entries', "The ZIP contains more than {$this->maxEntries} entries. Export the WhatsApp chat without media.");
            }

            $candidateIndices = [];
            $normalizedNames = [];
            $rawNames = [];
            $seenNames = [];
            $duplicateNames = [];
            $mediaIgnored = 0;
            $textEntryCount = 0;

            for ($index = 0; $index < $total; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }

                $rawName = (string) ($stat['name'] ?? '');
                $name = $this->normalizeName($rawName);

                if ($name === '') {
                    throw new FrocImportException('zip_unsafe_path', 'The ZIP contains an empty file name.');
                }
                if ($this->isUnsafePath($name)) {
                    throw new FrocImportException('zip_unsafe_path', 'The ZIP contains an unsafe file path.');
                }
                if ($this->isSymlink($zip, $index, $stat)) {
                    throw new FrocImportException('zip_symlink', 'The ZIP contains an unsupported symbolic link entry.');
                }
                if ($this->isDirectory($zip, $index, $name, $stat)) {
                    continue;
                }
                if ($this->isSystemMetadata($name)) {
                    continue;
                }
                if (! str_ends_with(strtolower($name), '.txt')) {
                    $mediaIgnored++;

                    continue;
                }

                $textEntryCount++;
                $candidateIndices[] = $index;
                $normalizedNames[$index] = $name;
                $rawNames[$index] = $rawName;
                if (isset($seenNames[$name])) {
                    $duplicateNames[$name] = true;
                } else {
                    $seenNames[$name] = true;
                }
            }

            if ($textEntryCount === 0) {
                throw new FrocImportException('zip_no_text', 'No .txt chat export was found inside the ZIP. Export the WhatsApp chat with its text file.');
            }
            if ($textEntryCount > $this->maxTextEntries) {
                throw new FrocImportException('zip_too_many_text_entries', "The ZIP contains more than {$this->maxTextEntries} text files. Export a single WhatsApp chat.");
            }
            if (! $supportsIndexStreams && $duplicateNames !== []) {
                throw new FrocImportException('zip_ambiguous_entries', 'The ZIP contains duplicate chat file names that cannot be safely distinguished.');
            }

            $ordered = $this->orderTextEntries($candidateIndices, $normalizedNames);
            $parts = [];
            $extracted = 0;

            foreach ($ordered as $index) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }
                $declaredSize = (int) ($stat['size'] ?? 0);
                $compressed = max(1, (int) ($stat['comp_size'] ?? 1));
                if ($declaredSize > $this->maxExtractedBytes) {
                    throw new FrocImportException('zip_text_too_large', "A single text entry exceeds the {$this->humanBytes($this->maxExtractedBytes)} extracted-text limit.");
                }
                if ($declaredSize / $compressed > $this->maxCompressionRatio) {
                    throw new FrocImportException('zip_unsafe_compression', 'The ZIP has an unsafe compression ratio in a text entry.');
                }
                if ($extracted + $declaredSize > $this->maxExtractedBytes) {
                    throw new FrocImportException('zip_text_too_large', "The extracted text is larger than the {$this->humanBytes($this->maxExtractedBytes)} import limit. Remove unrelated chat history and try again.");
                }

                $parts[] = $this->streamEntry($zip, $index, $rawNames[$index], $declaredSize, $supportsIndexStreams);
                $extracted += $declaredSize;
            }

            return new FrocZipReadResult(
                text: implode("\n", $parts),
                totalEntries: $total,
                textEntries: $textEntryCount,
                extractedBytes: $extracted,
                mediaEntriesIgnored: $mediaIgnored,
            );
        } finally {
            $zip->close();
        }
    }

    private function streamEntry(ZipArchive $zip, int $index, string $rawName, int $declaredSize, bool $supportsIndexStreams): string
    {
        $stream = $supportsIndexStreams
            ? $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED)
            : $zip->getStream($rawName);

        if ($stream === false) {
            throw new FrocImportException('zip_stream_failed', 'A text entry in the ZIP could not be read.');
        }

        try {
            $buffer = '';
            $read = 0;

            while (! feof($stream)) {
                $chunk = fread($stream, $this->readChunkBytes);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if (str_contains($chunk, "\0")) {
                    throw new FrocImportException('zip_invalid_text', 'A text entry in the ZIP is not valid plain text.');
                }

                $read += strlen($chunk);
                $buffer .= $chunk;

                if ($read > $declaredSize || strlen($buffer) > $this->maxExtractedBytes) {
                    throw new FrocImportException('zip_text_too_large', "The extracted text is larger than the {$this->humanBytes($this->maxExtractedBytes)} import limit. Remove unrelated chat history and try again.");
                }
            }

            if (! mb_check_encoding($buffer, 'UTF-8')) {
                throw new FrocImportException('zip_invalid_text', 'A text entry in the ZIP is not valid UTF-8 text.');
            }

            return $buffer;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Resolve originating OS and external attributes for an entry.
     *
     * Prefers the explicit getExternalAttributesIndex accessor (which also
     * exposes the originating OS) and falls back to the stat array. A zero
     * attribute value is treated as "unknown" (null) so we neither assume a type
     * nor reject legitimate entries whose type cannot be read.
     *
     * @param  array<int, mixed>  $stat
     * @return array{os: int, attrs: int}|null
     */
    private function externalAttributes(ZipArchive $zip, int $index, array $stat): ?array
    {
        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $os = 0;
            $attrs = 0;
            if ($zip->getExternalAttributesIndex($index, $os, $attrs) === true) {
                if ($attrs === 0) {
                    return null;
                }

                return ['os' => $os, 'attrs' => $attrs];
            }
        }

        $attrs = (int) ($stat['external_attrs'] ?? 0);
        if ($attrs === 0) {
            return null;
        }

        return ['os' => 0, 'attrs' => $attrs];
    }

    /**
     * @param  array<int, mixed>  $stat
     */
    private function isSymlink(ZipArchive $zip, int $index, array $stat): bool
    {
        $info = $this->externalAttributes($zip, $index, $stat);
        if ($info === null) {
            return false;
        }

        // The Unix file type lives in the high mode bits (S_IFMT = 0xF000).
        // A symbolic link is S_IFLNK = 0xA000.
        $type = ($info['attrs'] >> 16) & 0xF000;

        return $type === 0xA000;
    }

    /**
     * @param  array<int, mixed>  $stat
     */
    private function isDirectory(ZipArchive $zip, int $index, string $name, array $stat): bool
    {
        if (str_ends_with($name, '/')) {
            return true;
        }

        $info = $this->externalAttributes($zip, $index, $stat);
        if ($info === null) {
            return false;
        }

        // S_IFDIR = 0x4000.
        $type = ($info['attrs'] >> 16) & 0xF000;

        return $type === 0x4000;
    }

    private function isSystemMetadata(string $name): bool
    {
        $base = strtolower(basename($name));

        return str_starts_with($name, '__MACOSX/')
            || $base === '.ds_store'
            || $base === 'thumbs.db'
            || $base === '.spotlight-v100'
            || $base === '.trashes';
    }

    /**
     * Normalize a name for *comparison only*.
     *
     * Backslashes are treated as path separators (so `..\` becomes `../` and is
     * still caught as traversal). A single leading `./` is stripped. We never
     * collapse `../`, never strip a leading `/`, and never rewrite drive letters
     * or scheme prefixes — those remain detectable as unsafe.
     */
    private function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);

        if (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }

        return $name;
    }

    private function isUnsafePath(string $name): bool
    {
        // Absolute Unix path.
        if (str_starts_with($name, '/')) {
            return true;
        }

        // Windows drive letter, e.g. C:\chat.txt or C:/chat.txt.
        if (preg_match('#^[A-Za-z]:[/\\\\]#', $name) || preg_match('#^[A-Za-z]:$#', $name)) {
            return true;
        }

        // URI / scheme, e.g. file:///path or file://chat.txt.
        if (str_contains($name, '://')) {
            return true;
        }

        // Parent-directory traversal.
        if (str_contains($name, '../') || str_contains($name, '..\\') || $name === '..' || str_ends_with($name, '/..')) {
            return true;
        }

        // NUL byte.
        return str_contains($name, "\0");
    }

    /**
     * @param  array<int, int>  $indices
     * @param  array<int, string>  $normalizedNames
     * @return array<int, int>
     */
    private function orderTextEntries(array $indices, array $normalizedNames): array
    {
        $ranked = [];
        foreach ($indices as $index) {
            $base = strtolower(basename($normalizedNames[$index] ?? ''));
            if ($base === '_chat.txt') {
                $priority = 0;
            } elseif (str_starts_with($base, 'whatsapp chat')) {
                $priority = 1;
            } else {
                $priority = 2;
            }
            $ranked[] = ['index' => $index, 'priority' => $priority, 'name' => $base];
        }

        usort($ranked, fn (array $a, array $b): int => $a['priority'] <=> $b['priority'] ?: $a['name'] <=> $b['name']);

        return array_column($ranked, 'index');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return (string) round($bytes / (1024 * 1024)).' MB';
        }

        return (string) round($bytes / 1024).' KB';
    }
}
