<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Parsing;

use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookPreflight;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TeleStaffRosterWorkbookFactory as Factory;
use Tests\TestCase;
use ZipArchive;

final class TeleStaffWorkbookPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::cleanup();

        parent::tearDown();
    }

    public function test_a_clean_workbook_passes_and_returns_the_print_strings(): void
    {
        $result = (new TeleStaffWorkbookPreflight)->inspect(Factory::build());

        $this->assertSame('xl/worksheets/sheet1.xml', $result['sheetPart']);
        $this->assertStringContainsString('[10/30/2026]', (string) $result['oddHeader']);
        $this->assertStringContainsString('&L10/30/2026 09:14:15&R', (string) $result['oddFooter']);
    }

    public function test_the_legitimate_drawing_parts_of_the_export_are_allowed(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/drawings/drawing1.xml', '<?xml version="1.0"?><wsDr/>');
        Factory::addEntry($path, 'xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="../media/image1.png"/></Relationships>');
        Factory::addEntry($path, 'xl/media/image1.png', "\x89PNG synthetic");
        Factory::addEntry($path, 'xl/printerSettings/printerSettings1.bin', "\x01\x02\x03synthetic");

        $this->assertSame('xl/worksheets/sheet1.xml', (new TeleStaffWorkbookPreflight)->inspect($path)['sheetPart']);
    }

    public function test_a_missing_file_fails_the_read(): void
    {
        $this->assertFails('read_failed', sys_get_temp_dir().'/does-not-exist-'.uniqid().'.xlsx');
    }

    public function test_it_rejects_a_file_over_the_size_limit(): void
    {
        $path = Factory::build();
        config(['daily_roster.workbook.max_bytes' => 1024]);

        $this->assertFails('file_too_large', $path);
    }

    public function test_it_rejects_a_file_without_the_zip_signature(): void
    {
        $path = Factory::temporaryPath();
        file_put_contents($path, 'plain text pretending to be a workbook');

        $this->assertFails('not_a_zip', $path);
    }

    public function test_it_rejects_a_truncated_zip(): void
    {
        $path = Factory::build();
        $bytes = (string) file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, (int) (strlen($bytes) / 2)));

        $this->assertFails('not_a_zip', $path);
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenParts(): array
    {
        return [
            'macro project' => ['xl/vbaProject.bin'],
            'external link' => ['xl/externalLinks/externalLink1.xml'],
            'embedded object' => ['xl/embeddings/oleObject1.bin'],
            'second custom part' => ['customXml/item1.xml'],
            'image of another type' => ['xl/media/image1.svg'],
            'root level file' => ['payload.exe'],
        ];
    }

    #[DataProvider('forbiddenParts')]
    public function test_it_rejects_a_part_outside_the_allowlist(string $part): void
    {
        $path = Factory::build();
        Factory::addEntry($path, $part, '<x/>');

        $this->assertFails('part_not_allowed', $path);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeNames(): array
    {
        return [
            'parent directory' => ['../evil.xml'],
            'nested parent directory' => ['xl/../../evil.xml'],
            'backslash' => ['xl\\worksheets\\evil.xml'],
            'leading slash' => ['/xl/evil.xml'],
            'case-folded duplicate' => ['XL/WORKBOOK.XML'],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function test_it_rejects_an_unsafe_entry_name(string $name): void
    {
        $path = Factory::build();
        Factory::addEntry($path, $name, '<x/>');

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_it_rejects_an_exact_duplicate_entry_name(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/sharedStringz.xml', '<x/>');
        Factory::patchEntryName($path, 'xl/sharedStringz.xml', 'xl/sharedStrings.xml');

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_it_rejects_a_nul_in_an_entry_name(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/media/imageX1.png', 'synthetic');
        Factory::patchEntryName($path, 'xl/media/imageX1.png', "xl/media/image1\0.png");

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_it_rejects_an_encrypted_entry(): void
    {
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            $this->markTestSkipped('This PHP build cannot write encrypted zip entries.');
        }

        $path = Factory::build();
        Factory::encryptEntry($path, 'xl/styles.xml');

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_it_rejects_a_compression_method_other_than_store_or_deflate(): void
    {
        if (! ZipArchive::isCompressionMethodSupported(ZipArchive::CM_BZIP2)) {
            $this->markTestSkipped('This PHP build cannot write bzip2 zip entries.');
        }

        $path = Factory::build();
        Factory::setEntryCompression($path, 'xl/styles.xml', ZipArchive::CM_BZIP2);

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_stored_entries_are_accepted(): void
    {
        $path = Factory::build();
        Factory::setEntryCompression($path, 'xl/styles.xml', ZipArchive::CM_STORE);

        $this->assertSame('xl/worksheets/sheet1.xml', (new TeleStaffWorkbookPreflight)->inspect($path)['sheetPart']);
    }

    public function test_it_rejects_too_many_entries(): void
    {
        $path = Factory::build();
        config(['daily_roster.workbook.max_entries' => 3]);

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_it_stops_a_zip_bomb_entry_at_the_per_entry_limit(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/media/image1.png', str_repeat("\0", 9 * 1024 * 1024));

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_it_rejects_an_entry_over_the_compression_ratio(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/media/image1.png', str_repeat('A', 1024 * 1024));

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_it_rejects_a_package_over_the_total_limit(): void
    {
        $path = Factory::build();
        config(['daily_roster.workbook.max_total_uncompressed_bytes' => 1024]);

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_a_lied_about_size_still_stops_at_the_limit(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/media/image1.png', str_repeat('A', 1024 * 1024));
        $this->lowerDeclaredSize($path, 'xl/media/image1.png');
        config(['daily_roster.workbook.max_entry_ratio' => 1000000, 'daily_roster.workbook.max_entry_uncompressed_bytes' => 1024 * 512]);

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_a_lied_about_size_inside_the_limits_is_still_rejected(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/media/image1.png', str_repeat('A', 4096));
        $this->lowerDeclaredSize($path, 'xl/media/image1.png');

        $this->assertFails('zip_entry_unsafe', $path);
    }

    public function test_it_rejects_an_external_relationship(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.test/x" TargetMode="External"/></Relationships>');

        $this->assertFails('external_relationship', $path);
    }

    public function test_it_rejects_an_absolute_uri_relationship_without_a_target_mode(): void
    {
        $path = Factory::build();
        Factory::editEntry($path, 'xl/_rels/workbook.xml.rels', static fn (string $xml): string => str_replace(
            '</Relationships>',
            '<Relationship Id="rId99" Type="x" Target="file:///etc/passwd"/></Relationships>',
            $xml,
        ));

        $this->assertFails('external_relationship', $path);
    }

    public function test_it_rejects_a_doctype(): void
    {
        $path = Factory::build();
        Factory::editEntry($path, 'xl/styles.xml', static fn (string $xml): string => '<!DOCTYPE x [<!ENTITY a "b">]>'.$xml);

        $this->assertFails('doctype_rejected', $path);
    }

    public function test_it_rejects_a_utf16_doctype(): void
    {
        $path = Factory::build();
        $utf16 = "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY a "b">]><x/>', 'UTF-16LE', 'UTF-8');
        Factory::editEntry($path, 'docProps/app.xml', static fn (): string => $utf16);

        $this->assertFails('doctype_rejected', $path);
    }

    public function test_it_rejects_a_part_that_does_not_start_as_xml(): void
    {
        $path = Factory::build();
        Factory::editEntry($path, 'docProps/app.xml', static fn (): string => 'not xml at all');

        $this->assertFails('doctype_rejected', $path);
    }

    public function test_it_rejects_a_formula(): void
    {
        $path = Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
            $sheet->setCellValue('L1', '=1+1');
        }]);

        $this->assertFails('formula_rejected', $path);
    }

    public function test_it_rejects_an_error_cell(): void
    {
        $path = Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
            $sheet->setCellValueExplicit('L1', '#REF!', DataType::TYPE_ERROR);
        }]);

        $this->assertFails('formula_rejected', $path);
    }

    public function test_it_rejects_a_second_sheet(): void
    {
        $path = Factory::build(['customize' => static function (Spreadsheet $book): void {
            $book->createSheet()->setTitle('Extra');
        }]);

        $this->assertFails('sheet_mismatch', $path);
    }

    public function test_it_rejects_a_sheet_with_another_name(): void
    {
        $path = Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
            $sheet->setTitle('Something Else');
        }]);

        $this->assertFails('sheet_mismatch', $path);
    }

    public function test_it_rejects_an_orphan_worksheet_part(): void
    {
        $path = Factory::build();
        Factory::addEntry($path, 'xl/worksheets/sheet2.xml', '<?xml version="1.0"?><worksheet/>');

        $this->assertFails('sheet_mismatch', $path);
    }

    public function test_it_rejects_a_macro_enabled_workbook_content_type(): void
    {
        $path = Factory::build();
        Factory::editEntry($path, '[Content_Types].xml', static fn (string $xml): string => str_replace(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
            $xml,
        ));

        $this->assertFails('part_not_allowed', $path);
    }

    public function test_it_rejects_too_many_rows(): void
    {
        $path = Factory::build();
        config(['daily_roster.workbook.max_rows' => 10]);

        $this->assertFails('zip_limit_exceeded', $path);
    }

    public function test_it_fails_a_malformed_sheet_as_a_read_failure(): void
    {
        $path = Factory::build();
        Factory::editEntry($path, 'xl/worksheets/sheet1.xml', static fn (string $xml): string => substr($xml, 0, (int) (strlen($xml) / 2)));

        $this->assertFails('read_failed', $path);
    }

    /** Writes a false uncompressed size into both the central and the local header of an entry. */
    private function lowerDeclaredSize(string $path, string $name): void
    {
        $bytes = (string) file_get_contents($path);
        $central = strpos($bytes, "PK\x01\x02");
        while ($central !== false) {
            $nameLength = unpack('v', substr($bytes, $central + 28, 2))[1];
            if (substr($bytes, $central + 46, $nameLength) === $name) {
                $local = unpack('V', substr($bytes, $central + 42, 4))[1];
                $patched = substr_replace($bytes, pack('V', 10), $central + 24, 4);
                file_put_contents($path, substr_replace($patched, pack('V', 10), $local + 22, 4));

                return;
            }
            $central = strpos($bytes, "PK\x01\x02", $central + 4);
        }

        $this->fail('Entry not found in the central directory.');
    }

    private function assertFails(string $errorCode, string $path): void
    {
        try {
            (new TeleStaffWorkbookPreflight)->inspect($path);
            $this->fail("Expected {$errorCode}, the workbook passed.");
        } catch (TeleStaffWorkbookException $exception) {
            $this->assertSame($errorCode, $exception->errorCode);
        }
    }
}
