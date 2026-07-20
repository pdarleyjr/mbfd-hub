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

        try {
            $total = $zip->numFiles;
            if ($total > $this->maxEntries) {
                throw new FrocImportException('zip_too_many_entries', "The ZIP contains more than {$this->maxEntries} entries. Export the WhatsApp chat without media.");
            }

            $textIndices = [];
            $mediaIgnored = 0;

            for ($index = 0; $index < $total; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }

                $name = $this->normalizeName((string) ($stat['name'] ?? ''));
                if ($name === '' || $this->isUnsafePath($name)) {
                    throw new FrocImportException('zip_unsafe_path', 'The ZIP contains an unsafe file path.');
                }
                if ($this->isSymlink($stat)) {
                    throw new FrocImportException('zip_symlink', 'The ZIP contains an unsupported symbolic link entry.');
                }
                if ($this->isDirectory($name, $stat)) {
                    continue;
                }
                if ($this->isSystemMetadata($name)) {
                    continue;
                }
                if (! str_ends_with(strtolower($name), '.txt')) {
                    $mediaIgnored++;

                    continue;
                }

                $textIndices[] = $index;
            }

            if ($textIndices === []) {
                throw new FrocImportException('zip_no_text', 'No .txt chat export was found inside the ZIP. Export the WhatsApp chat with its text file.');
            }
            if (count($textIndices) > $this->maxTextEntries) {
                throw new FrocImportException('zip_too_many_text_entries', "The ZIP contains more than {$this->maxTextEntries} text files. Export a single WhatsApp chat.");
            }

            $ordered = $this->orderTextEntries($zip, $textIndices);
            $parts = [];
            $extracted = 0;

            foreach ($ordered as $index) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }
                $size = (int) ($stat['size'] ?? 0);
                $compressed = max(1, (int) ($stat['comp_size'] ?? 1));
                if ($size > $this->maxExtractedBytes) {
                    throw new FrocImportException('zip_text_too_large', "A single text entry exceeds the {$this->humanBytes($this->maxExtractedBytes)} extracted-text limit.");
                }
                if ($size / $compressed > $this->maxCompressionRatio) {
                    throw new FrocImportException('zip_unsafe_compression', 'The ZIP has an unsafe compression ratio in a text entry.');
                }
                if ($extracted + $size > $this->maxExtractedBytes) {
                    throw new FrocImportException('zip_text_too_large', "The extracted text is larger than the {$this->humanBytes($this->maxExtractedBytes)} import limit. Remove unrelated chat history and try again.");
                }

                $name = (string) ($stat['name'] ?? '');
                $parts[] = $this->streamEntry($zip, $name, $size);
                $extracted += $size;
            }

            return new FrocZipReadResult(
                text: implode("\n", $parts),
                totalEntries: $total,
                textEntries: count($textIndices),
                extractedBytes: $extracted,
                mediaEntriesIgnored: $mediaIgnored,
            );
        } finally {
            $zip->close();
        }
    }

    private function streamEntry(ZipArchive $zip, string $name, int $declaredSize): string
    {
        $stream = $zip->getStream($name);
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

            return $buffer;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $stat
     */
    private function isSymlink(array $stat): bool
    {
        $attrs = (int) ($stat['external_attrs'] ?? 0);
        if ($attrs === 0) {
            return false;
        }
        $mode = $attrs >> 16;

        return ($mode & 0xF000) === 0xA000;
    }

    /**
     * @param  array<int, mixed>  $stat
     */
    private function isDirectory(string $name, array $stat): bool
    {
        if (str_ends_with($name, '/')) {
            return true;
        }
        $attrs = (int) ($stat['external_attrs'] ?? 0);
        if ($attrs === 0) {
            return false;
        }
        $mode = $attrs >> 16;

        return ($mode & 0xF000) === 0x4000;
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

    private function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        // Strip a single leading "./" only; never collapse "../" or leading "/",
        // which must remain detectable as unsafe paths.
        if (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }

        return $name;
    }

    private function isUnsafePath(string $name): bool
    {
        if (str_starts_with($name, '/')) {
            return true;
        }
        if (str_contains($name, '../') || str_contains($name, '..\\')) {
            return true;
        }

        return str_contains($name, "\0");
    }

    /**
     * @param  ZipArchive  $zip
     * @param  array<int, int>  $indices
     * @return array<int, int>
     */
    private function orderTextEntries(ZipArchive $zip, array $indices): array
    {
        $ranked = [];
        foreach ($indices as $index) {
            $stat = $zip->statIndex($index);
            $name = strtolower(basename((string) ($stat['name'] ?? '')));
            if ($name === '_chat.txt') {
                $priority = 0;
            } elseif (str_starts_with($name, 'whatsapp chat')) {
                $priority = 1;
            } else {
                $priority = 2;
            }
            $ranked[] = ['index' => $index, 'priority' => $priority, 'name' => $name];
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
