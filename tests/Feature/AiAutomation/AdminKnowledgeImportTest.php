<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Services\AiAutomation\Knowledge\KnowledgeDocumentImporter;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

/** Word, PDF and text import for Knowledge & Instructions: text only, 5 MB or less, nothing kept, nothing run. */
final class AdminKnowledgeImportTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_a_word_document_gives_its_title_headings_lists_and_paragraphs(): void
    {
        $path = $this->docx([
            ['Title', 'Weekend Contact Rules'],
            ['', 'On Saturday and Sunday there is no admin staff.'],
            ['Heading2', 'Who to call'],
            ['List', 'Call 300 first'],
            ['List', 'Then text your officer'],
        ]);

        $result = (new KnowledgeDocumentImporter)->importPath($path, 'rules.docx');

        self::assertSame('Weekend Contact Rules', $result['title']);
        self::assertStringContainsString("On Saturday and Sunday there is no admin staff.\n\n## Who to call\n\n- Call 300 first\n- Then text your officer", $result['body']);
        self::assertFalse($result['truncated']);
    }

    public function test_a_word_document_without_a_title_style_takes_its_title_from_the_file_name(): void
    {
        $result = (new KnowledgeDocumentImporter)->importPath($this->docx([['', 'Just a paragraph of guidance.']]), 'bunker_gear-dropoff.docx');

        self::assertSame('Bunker gear dropoff', $result['title']);
    }

    public function test_a_pdf_gives_its_text(): void
    {
        $result = (new KnowledgeDocumentImporter)->importPath($this->pdf('Call or text before heading to Station 2.'), 'procedure.pdf');

        self::assertStringContainsString('Call or text before heading to Station 2.', $result['body']);
        self::assertSame('Procedure', $result['title']);
    }

    public function test_text_and_markdown_files_are_read_as_utf8_with_a_windows_fallback(): void
    {
        $importer = new KnowledgeDocumentImporter;

        self::assertSame("Line one\n\nLine two", $importer->importPath($this->file("\xEF\xBB\xBFLine one\r\n\r\nLine two\r\n"), 'a.txt')['body']);
        self::assertSame('# Heading', $importer->importPath($this->file('# Heading'), 'a.md')['body']);
        self::assertSame("Caf\u{00E9} hours", $importer->importPath($this->file("Caf\xE9 hours"), 'a.txt')['body']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function rejected(): array
    {
        return [
            'program' => ['program.exe', 'MZ binary', 'Word (.docx), PDF or text'],
            'script' => ['install.sh', '#!/bin/sh', 'Word (.docx), PDF or text'],
            'html' => ['page.html', '<script>alert(1)</script>', 'Word (.docx), PDF or text'],
            'old word' => ['old.doc', "\xD0\xCF\x11\xE0", 'Older Word files'],
            'text with null bytes' => ['bad.txt', "abc\0def", 'does not look like a text file'],
            'fake pdf' => ['fake.pdf', 'This is plain text pretending to be a PDF', 'not a real PDF'],
            'fake docx' => ['fake.docx', 'This is plain text pretending to be Word', 'not a real Word'],
            'empty' => ['empty.txt', '', 'empty'],
            'blank text' => ['blank.txt', "   \n\n  ", 'No readable text'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function test_other_types_and_fakes_are_refused_with_a_plain_sentence(string $name, string $contents, string $message): void
    {
        try {
            (new KnowledgeDocumentImporter)->importPath($this->file($contents), $name);
            self::fail('The file was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_file_over_five_megabytes_is_refused(): void
    {
        $path = $this->file(str_repeat('a', KnowledgeDocumentImporter::MAX_BYTES + 1));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than 5 MB');
        (new KnowledgeDocumentImporter)->importPath($path, 'big.txt');
    }

    public function test_a_file_just_under_the_limit_is_accepted_and_long_text_is_shortened(): void
    {
        $result = (new KnowledgeDocumentImporter)->importPath($this->file(str_repeat("A sentence of guidance.\n", 3000)), 'long.txt');

        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(KnowledgeDocumentImporter::MAX_CHARACTERS, mb_strlen($result['body']));
    }

    public function test_a_word_file_with_an_entity_declaration_is_refused_and_nothing_external_is_read(): void
    {
        $evil = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be read safely');
        (new KnowledgeDocumentImporter)->importPath($this->docxFromXml($evil), 'evil.docx');
    }

    public function test_a_zip_that_is_not_a_word_document_is_refused(): void
    {
        $path = $this->tempPath('zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        (new KnowledgeDocumentImporter)->importPath($path, 'archive.docx');
    }

    public function test_macros_and_embedded_content_are_never_read_or_run(): void
    {
        $path = $this->tempPath('docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', $this->wordXml([['', 'Plain words only.']]));
        $zip->addFromString('word/vbaProject.bin', 'EVIL MACRO PAYLOAD');
        $zip->addFromString('word/embeddings/oleObject1.bin', 'EVIL OBJECT');
        $zip->close();

        $result = (new KnowledgeDocumentImporter)->importPath($path, 'macro.docx');

        self::assertSame('Plain words only.', $result['body']);
        self::assertStringNotContainsString('EVIL', $result['body']);
    }

    public function test_an_uploaded_file_object_is_read_without_being_stored(): void
    {
        $upload = UploadedFile::fake()->createWithContent('Notes.txt', 'Call 300 on weekends.');

        $result = (new KnowledgeDocumentImporter)->import($upload);

        self::assertSame('Call 300 on weekends.', $result['body']);
        self::assertSame('Notes', $result['title']);
    }

    private function tempPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-import-').'.'.$extension;
        $this->cleanup[] = $path;

        return $path;
    }

    private function file(string $contents): string
    {
        $path = $this->tempPath('bin');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param list<array{string, string}> $paragraphs style, text */
    private function wordXml(array $paragraphs): string
    {
        $body = '';
        foreach ($paragraphs as [$style, $text]) {
            $props = $style === '' ? '' : '<w:pPr><w:pStyle w:val="'.$style.'"/>'.($style === 'List' ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '').'</w:pPr>';
            $body .= '<w:p>'.$props.'<w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';
    }

    /** @param list<array{string, string}> $paragraphs */
    private function docx(array $paragraphs): string
    {
        return $this->docxFromXml($this->wordXml($paragraphs));
    }

    private function docxFromXml(string $xml): string
    {
        $path = $this->tempPath('docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }

    private function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $this->file($pdf);
    }
}
