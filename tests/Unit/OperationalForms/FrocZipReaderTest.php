<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use App\Services\OperationalForms\FrocImportException;
use App\Services\OperationalForms\FrocZipReader;
use PHPUnit\Framework\TestCase;

class FrocZipReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * @param  array<int, array{name: string, content?: string, store?: bool, external?: int}>  $entries
     */
    private function zipPath(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'froc-zip');
        unlink($file);
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach ($entries as $entry) {
            $method = ($entry['store'] ?? false) ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE;
            $zip->addFromString($entry['name'], $entry['content'] ?? '', $method);
            if (($entry['store'] ?? false)) {
                $zip->setCompressionIndex($zip->numFiles - 1, \ZipArchive::CM_STORE);
            }
            if (array_key_exists('external', $entry)) {
                $zip->setExternalAttributesIndex($zip->numFiles - 1, 3, $entry['external']);
            }
        }
        $zip->close();
        $this->tempPaths[] = $file;

        return $file;
    }

    private function reader(int $maxEntries = 500, int $maxText = 10, int $maxExtracted = 1_048_576, float $maxRatio = 100): FrocZipReader
    {
        return new FrocZipReader($maxEntries, $maxText, $maxExtracted, $maxRatio);
    }

    /**
     * Build a stored (no compression) ZIP from raw bytes, preserving names that
     * contain NUL or are empty — which ZipArchive::addFromString collapses.
     *
     * @param  array<int, array{0: string, 1: string}>  $entries  [name, content]
     */
    private function zipPathRaw(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'froc-raw');
        unlink($file);
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($entries as [$name, $data]) {
            $crc = crc32($data);
            $nl = strlen($name);
            $dl = strlen($data);
            $lh = pack('VvvvvvVVVv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $dl, $dl, $nl).$name.$data;
            $ch = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $dl, $dl, $nl, 0, 0, 0, 0, 0, $offset).$name;
            $local .= $lh;
            $central .= $ch;
            $offset += strlen($lh);
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0);
        file_put_contents($file, $local.$central.$end);
        $this->tempPaths[] = $file;

        return $file;
    }

    public function test_valid_single_text_entry_is_extracted(): void
    {
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => "R6 starting mileage: 113969"]]);
        $result = $this->reader()->read($path);

        $this->assertStringContainsString('113969', $result->text);
        $this->assertSame(1, $result->totalEntries);
        $this->assertSame(1, $result->textEntries);
        $this->assertSame(0, $result->mediaEntriesIgnored);
    }

    public function test_media_is_ignored_and_never_included_in_extracted_text(): void
    {
        $path = $this->zipPath([
            ['name' => '_chat.txt', 'content' => 'REAL_CHAT_TEXT_R6'],
            ['name' => 'IMG-0001.jpg', 'content' => 'SENTINEL_MEDIA_BYTES_DO_NOT_LEAK', 'store' => true],
            ['name' => 'VID-0001.mp4', 'content' => str_repeat('X', 50_000), 'store' => true],
        ]);
        $result = $this->reader()->read($path);

        $this->assertStringContainsString('REAL_CHAT_TEXT_R6', $result->text);
        $this->assertStringNotContainsString('SENTINEL_MEDIA_BYTES_DO_NOT_LEAK', $result->text);
        $this->assertSame(2, $result->mediaEntriesIgnored);
        $this->assertSame(strlen('REAL_CHAT_TEXT_R6'), $result->extractedBytes);
    }

    public function test_deterministic_selection_prefers_chat_txt_first(): void
    {
        $path = $this->zipPath([
            ['name' => 'notes.txt', 'content' => 'SECOND'],
            ['name' => '_chat.txt', 'content' => 'FIRST'],
            ['name' => 'WhatsApp Chat with Bob.txt', 'content' => 'THIRD'],
        ]);
        $result = $this->reader()->read($path);

        $this->assertStringContainsString('FIRST', $result->text);
        $this->assertLessThan(strpos($result->text, 'SECOND'), strpos($result->text, 'FIRST'));
    }

    public function test_accepts_zip_larger_than_two_megabytes_with_media(): void
    {
        $entries = [
            ['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969'],
            ['name' => 'IMG-0001.jpg', 'content' => random_bytes(2_200_000), 'store' => true],
        ];
        $path = $this->zipPath($entries);

        $result = $this->reader()->read($path);
        $this->assertStringContainsString('113969', $result->text);
        $this->assertSame(1, $result->textEntries);
        $this->assertSame(1, $result->mediaEntriesIgnored);
    }

    public function test_rejects_too_many_total_entries(): void
    {
        $entries = [['name' => '_chat.txt', 'content' => 'R6']];
        for ($i = 0; $i < 501; $i++) {
            $entries[] = ['name' => "IMG-{$i}.jpg", 'content' => 'x', 'store' => true];
        }
        $path = $this->zipPath($entries);

        try {
            $this->reader(500)->read($path);
            $this->fail('Expected rejection of too many entries.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_too_many_entries', $exception->reason);
        }
    }

    public function test_accepts_at_maximum_five_hundred_entries(): void
    {
        $entries = [['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969']];
        for ($i = 0; $i < 499; $i++) {
            $entries[] = ['name' => "IMG-{$i}.jpg", 'content' => 'x', 'store' => true];
        }
        $path = $this->zipPath($entries);

        $result = $this->reader(500, 10)->read($path);
        $this->assertSame(500, $result->totalEntries);
        $this->assertSame(499, $result->mediaEntriesIgnored);
        $this->assertStringContainsString('113969', $result->text);
    }

    public function test_accepts_many_media_entries_with_one_text(): void
    {
        $entries = [['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969']];
        for ($i = 0; $i < 60; $i++) {
            $entries[] = ['name' => "IMG-{$i}.jpg", 'content' => 'x', 'store' => true];
        }
        $path = $this->zipPath($entries);

        $result = $this->reader(500, 10)->read($path);
        $this->assertSame(61, $result->totalEntries);
        $this->assertSame(60, $result->mediaEntriesIgnored);
        $this->assertStringContainsString('113969', $result->text);
    }

    public function test_rejects_too_many_text_entries(): void
    {
        $entries = [];
        for ($i = 0; $i < 11; $i++) {
            $entries[] = ['name' => "chat-{$i}.txt", 'content' => "R6 message {$i}"];
        }
        $path = $this->zipPath($entries);

        try {
            $this->reader(500, 10)->read($path);
            $this->fail('Expected rejection of too many text entries.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_too_many_text_entries', $exception->reason);
        }
    }

    public function test_accepts_maximum_ten_text_entries(): void
    {
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = ['name' => "chat-{$i}.txt", 'content' => "R6 message {$i}"];
        }
        $path = $this->zipPath($entries);

        $result = $this->reader(500, 10)->read($path);
        $this->assertSame(10, $result->textEntries);
        $this->assertStringContainsString('R6 message 9', $result->text);
    }

    public function test_extracted_text_exactly_one_mib_is_accepted(): void
    {
        // base64 of random data is valid UTF-8, incompressible, and exactly
        // the requested byte length (3:4 expansion).
        $exact = base64_encode(random_bytes(786_432)); // 1,048,576 bytes
        $this->assertSame(1_048_576, strlen($exact));
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => $exact]]);

        $result = $this->reader(500, 10, 1_048_576, 100_000)->read($path);
        $this->assertSame(1_048_576, $result->extractedBytes);
    }

    public function test_extracted_text_one_byte_over_is_rejected(): void
    {
        $exact = base64_encode(random_bytes(786_432)).'A'; // 1,048,577 bytes
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => $exact]]);

        try {
            $this->reader(500, 10, 1_048_576, 100_000)->read($path);
            $this->fail('Expected rejection of over-limit extracted text.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_text_too_large', $exception->reason);
        }
    }

    public function test_rejects_unsafe_compression_ratio(): void
    {
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => str_repeat('A', 900_000)]]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of unsafe compression ratio.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_compression', $exception->reason);
        }
    }

    public function test_accepts_compression_ratio_at_limit(): void
    {
        // Stored (uncompressed) entry has ratio exactly 1.0, which is within the
        // configured ceiling of 1.0.
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => str_repeat('A', 5000), 'store' => true]]);

        $result = $this->reader(500, 10, 1_048_576, 1.0)->read($path);
        $this->assertStringContainsString('AAAAA', $result->text);
    }

    public function test_rejects_compression_ratio_over_limit(): void
    {
        // Deflated highly-repetitive content has ratio far above 1.0.
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => str_repeat('A', 5000)]]);

        try {
            $this->reader(500, 10, 1_048_576, 1.0)->read($path);
            $this->fail('Expected rejection of over-limit compression ratio.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_compression', $exception->reason);
        }
    }

    public function test_rejects_traversal_path(): void
    {
        $path = $this->zipPath([['name' => '../chat.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of traversal path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_traversal_path_with_backslash(): void
    {
        $path = $this->zipPath([['name' => '..\\chat.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of backslash traversal path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_embedded_traversal_path(): void
    {
        $path = $this->zipPath([['name' => 'folder/../chat.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of embedded traversal path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_absolute_path(): void
    {
        $path = $this->zipPath([['name' => '/etc/passwd.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of absolute path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_windows_drive_path(): void
    {
        $path = $this->zipPath([['name' => 'C:\\chat.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of Windows drive path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_file_uri_scheme(): void
    {
        $path = $this->zipPath([['name' => 'file:///etc/chat.txt', 'content' => 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of file:// URI.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_nul_byte_in_name(): void
    {
        // PHP's ZipArchive strips NUL bytes from entry names on read, so a NUL
        // can never reach the reader through a real archive. The guard is kept
        // as defense-in-depth and exercised directly against the name-safety
        // logic, which must reject any NUL-bearing name.
        $reader = new FrocZipReader(500, 10, 1_048_576, 100);
        $method = new \ReflectionMethod($reader, 'isUnsafePath');

        $this->assertTrue($method->invoke($reader, "chat\x00.txt"));
        $this->assertTrue($method->invoke($reader, "folder/chat\x00.txt"));
    }

    public function test_rejects_empty_path(): void
    {
        $path = $this->zipPathRaw([['', 'R6']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of empty path.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_path', $exception->reason);
        }
    }

    public function test_rejects_zip_with_no_text(): void
    {
        $path = $this->zipPath([
            ['name' => 'IMG-1.jpg', 'content' => 'x', 'store' => true],
            ['name' => 'VID-1.mp4', 'content' => 'x', 'store' => true],
        ]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of archive with no text.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_no_text', $exception->reason);
        }
    }

    public function test_rejects_binary_renamed_text(): void
    {
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => "PK\x03\x04\x00\x00\x00".\chr(0).'binary']]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of binary renamed text.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_invalid_text', $exception->reason);
        }
    }

    public function test_rejects_invalid_utf8_text(): void
    {
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => "R6 notes\xFF\xFE\x00not valid"]]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of invalid UTF-8 text.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_invalid_text', $exception->reason);
        }
    }

    public function test_ignores_system_metadata_entries(): void
    {
        $path = $this->zipPath([
            ['name' => '__MACOSX/._chat.txt', 'content' => 'ignore'],
            ['name' => '.DS_Store', 'content' => 'ignore'],
            ['name' => '_chat.txt', 'content' => 'R6 real'],
        ]);

        $result = $this->reader()->read($path);
        $this->assertSame(1, $result->textEntries);
        $this->assertStringContainsString('R6 real', $result->text);
    }

    public function test_invalid_zip_is_rejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'froc-bad');
        file_put_contents($file, 'this is not a zip archive at all');
        $this->tempPaths[] = $file;

        try {
            $this->reader()->read($file);
            $this->fail('Expected rejection of invalid ZIP.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_open_failed', $exception->reason);
        }
    }

    public function test_rejects_oversized_single_text_entry(): void
    {
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => str_repeat('R6 started. ', 120_000)]]);

        try {
            $this->reader(500, 10, 1_048_576)->read($path);
            $this->fail('Expected rejection of oversized single text entry.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_text_too_large', $exception->reason);
        }
    }

    public function test_rejects_oversized_combined_text(): void
    {
        $path = $this->zipPath([
            ['name' => 'a.txt', 'content' => base64_encode(random_bytes(700_000))],
            ['name' => 'b.txt', 'content' => base64_encode(random_bytes(700_000))],
        ]);

        try {
            $this->reader(500, 10, 1_048_576)->read($path);
            $this->fail('Expected rejection of oversized combined text.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_text_too_large', $exception->reason);
        }
    }

    public function test_rejects_symlink_entry(): void
    {
        $symlinkMode = 0o120000 << 16;
        $path = $this->zipPath([
            ['name' => '_chat.txt', 'content' => 'R6'],
            ['name' => 'evil', 'content' => 'x', 'external' => $symlinkMode],
        ]);

        // Verify the runtime can expose the symlink attributes we set, mirroring
        // the reader's own lookup. If the build genuinely cannot represent them,
        // skip with a documented limitation rather than passing silently.
        $probe = new \ZipArchive();
        $probe->open($path);
        $os = 0;
        $attrs = 0;
        $readable = $probe->getExternalAttributesIndex(1, $os, $attrs) === true;
        $probe->close();

        if (! $readable || (($attrs >> 16) & 0xF000) !== 0xA000) {
            $this->markTestSkipped('ZipArchive cannot expose symlink external attributes on this build.');
        }

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of symlink entry.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_symlink', $exception->reason);
        }
    }

    public function test_duplicate_text_names_are_read_by_index_without_ambiguity(): void
    {
        // addFromString collapses truly identical names, so we use a name that
        // normalizes to the same string ("chat.txt") without being byte-identical.
        // Both candidates share a normalized name; the reader must still extract
        // each entry by its own index rather than risking the wrong one.
        $path = $this->zipPath([
            ['name' => 'chat.txt', 'content' => 'FIRST_R6'],
            ['name' => './chat.txt', 'content' => 'SECOND_R6'],
        ]);

        $result = $this->reader()->read($path);
        $this->assertStringContainsString('FIRST_R6', $result->text);
        $this->assertStringContainsString('SECOND_R6', $result->text);
        $this->assertSame(2, $result->textEntries);
    }

    public function test_bounded_stream_reads_respect_chunk_size(): void
    {
        $content = str_repeat('R6 line of activity. ', 40); // ~ 920 bytes
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => $content]]);

        // Force a tiny read chunk to exercise the streaming loop.
        $result = (new FrocZipReader(500, 10, 1_048_576, 100, 16))->read($path);
        $this->assertSame($content, $result->text);
    }

    public function test_no_temporary_extraction_or_media_files_are_written(): void
    {
        $before = $this->countTempTextFiles();
        $path = $this->zipPath([
            ['name' => '_chat.txt', 'content' => 'REAL_CHAT_TEXT_R6'],
            ['name' => 'IMG-0001.jpg', 'content' => 'SENTINEL_MEDIA_BYTES_DO_NOT_LEAK', 'store' => true],
        ]);

        $result = $this->reader()->read($path);

        $this->assertStringContainsString('REAL_CHAT_TEXT_R6', $result->text);
        $this->assertStringNotContainsString('SENTINEL_MEDIA_BYTES_DO_NOT_LEAK', $result->text);
        $this->assertSame($before, $this->countTempTextFiles(), 'Reader must not extract files to disk.');
    }

    private function countTempTextFiles(): int
    {
        $count = 0;
        foreach (new \DirectoryIterator(sys_get_temp_dir()) as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.txt')) {
                $count++;
            }
        }

        return $count;
    }
}
