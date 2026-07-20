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

    private function zipPath(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'froc-zip');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach ($entries as $entry) {
            $method = ($entry['store'] ?? false) ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE;
            $zip->addFromString($entry['name'], $entry['content'] ?? '', $method);
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

    public function test_rejects_symlink_entry(): void
    {
        $symlinkMode = 0o120000 << 16;
        $path = $this->zipPath([
            ['name' => '_chat.txt', 'content' => 'R6'],
            ['name' => 'evil', 'content' => 'x', 'external' => $symlinkMode],
        ]);

        // Symlink detection depends on ZipArchive exposing external attributes
        // for programmatically created entries, which some builds omit. The
        // guard remains in place for environments where it is detectable.
        $probe = new \ZipArchive();
        $probe->open($path);
        $stat = $probe->statIndex(1);
        $probe->close();
        $attrs = (int) ($stat['external_attrs'] ?? 0);
        if (! isset($stat['external_attrs']) || (($attrs >> 16) & 0xF000) !== 0xA000) {
            $this->markTestSkipped('ZipArchive does not expose symlink external attributes in this environment.');
        }

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of symlink entry.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_symlink', $exception->reason);
        }
    }

    public function test_rejects_unsafe_compression_ratio(): void
    {
        // 900 KB of a single repeating byte compresses to a few hundred bytes.
        $path = $this->zipPath([['name' => '_chat.txt', 'content' => str_repeat('A', 900_000)]]);

        try {
            $this->reader()->read($path);
            $this->fail('Expected rejection of unsafe compression ratio.');
        } catch (FrocImportException $exception) {
            $this->assertSame('zip_unsafe_compression', $exception->reason);
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
        // Incompressible, unique content so the compression-ratio guard does
        // not fire before the combined extracted-size guard.
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
}
