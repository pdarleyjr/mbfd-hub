<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Knowledge;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Reads the words out of a Word (.docx), PDF, text or Markdown file for the Knowledge & Instructions editor.
 *
 * Text only: nothing in the file is executed, macros, images, links to other files and embedded objects are
 * ignored, and the file itself is never stored (the caller keeps only the returned text). The file must be
 * 5 MB or smaller, and its real content must match its extension.
 */
final class KnowledgeDocumentImporter
{
    public const MAX_BYTES = 5_242_880;

    public const MAX_CHARACTERS = 30_000;

    public const EXTENSIONS = ['docx', 'pdf', 'txt', 'md'];

    private const MAX_ZIP_ENTRIES = 3000;

    private const MAX_XML_BYTES = 5_000_000;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{title: ?string, body: string, truncated: bool}
     *
     * @throws InvalidArgumentException with a sentence an administrator can act on
     */
    public function import(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('The file did not upload. Please try again.');
        }
        $path = (string) $file->getRealPath();

        return $this->importPath($path, (string) $file->getClientOriginalName(), $file->getSize() === false ? null : (int) $file->getSize());
    }

    /**
     * @return array{title: ?string, body: string, truncated: bool}
     *
     * @throws InvalidArgumentException
     */
    public function importPath(string $path, string $originalName, ?int $size = null): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'doc') {
            throw new InvalidArgumentException('Older Word files (.doc) cannot be read. Open it in Word, choose Save As, and pick "Word Document (.docx)".');
        }
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Please choose a Word (.docx), PDF or text (.txt) file.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The file could not be opened. Please try again.');
        }
        $size ??= (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than 5 MB. Use a shorter file, or copy just the part you need.');
        }
        if ($size === 0) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $title = null;
        $text = match ($extension) {
            'docx' => $this->fromDocx($path, $title),
            'pdf' => $this->fromPdf($path),
            default => $this->fromPlainText($path),
        };

        $text = self::tidy($text);
        if ($text === '') {
            throw new InvalidArgumentException($extension === 'pdf'
                ? 'No readable text was found. A scanned picture of a page cannot be read; please type or paste the words instead.'
                : 'No readable text was found in that file.');
        }

        $truncated = mb_strlen($text) > self::MAX_CHARACTERS;
        if ($truncated) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARACTERS));
        }

        return [
            'title' => $title !== null && $title !== '' ? mb_substr($title, 0, 200) : self::titleFromFilename($originalName),
            'body' => $text,
            'truncated' => $truncated,
        ];
    }

    public static function titleFromFilename(string $name): ?string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\s_\-]+/', ' ', $base));

        return $base === '' ? null : mb_substr(ucfirst($base), 0, 200);
    }

    private function fromPlainText(string $path): string
    {
        $contents = (string) file_get_contents($path);
        if (str_contains($contents, "\0")) {
            throw new InvalidArgumentException('That does not look like a text file. Use a .txt file, or a Word or PDF file.');
        }
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    private function fromPdf(string $path): string
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 5);
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            throw new InvalidArgumentException('That file is not a real PDF.');
        }

        try {
            // Bound decompression so a crafted PDF cannot exhaust memory, and never keep images.
            $config = new \Smalot\PdfParser\Config;
            $config->setDecodeMemoryLimit(20_000_000);
            $config->setRetainImageContent(false);
            $text = (new PdfParser([], $config))->parseFile($path)->getText();
        } catch (Throwable) {
            throw new InvalidArgumentException('That PDF could not be read. It may be protected with a password or damaged. Please type or paste the words instead.');
        }

        return self::joinWrappedLines((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8')));
    }

    private function fromDocx(string $path, ?string &$title): string
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04") {
            throw new InvalidArgumentException('That file is not a real Word (.docx) document.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('That Word file could not be opened. It may be damaged.');
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES || $zip->locateName('[Content_Types].xml') === false) {
                throw new InvalidArgumentException('That file is not a normal Word (.docx) document.');
            }
            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new InvalidArgumentException('That file is not a normal Word (.docx) document.');
            }
            if ((int) $stat['size'] > self::MAX_XML_BYTES) {
                throw new InvalidArgumentException('That Word document is too large to read. Use a shorter file.');
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml) || $xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('That Word document could not be read safely.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new InvalidArgumentException('That Word document could not be read. It may be damaged.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->textFromWordDom($dom, $title);
    }

    private function textFromWordDom(DOMDocument $dom, ?string &$title): string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMNode) {
            return '';
        }

        $blocks = [];
        foreach ($body->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'p') {
                $this->appendParagraph($blocks, $child, $xpath, $title);
            } elseif ($child->localName === 'tbl') {
                $rows = [];
                foreach ($xpath->query('.//w:tr', $child) ?: [] as $row) {
                    $cells = [];
                    foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                        $cells[] = trim((string) preg_replace('/\s+/u', ' ', $this->plainText($cell, $xpath)));
                    }
                    $line = trim(implode(' | ', array_filter($cells, static fn (string $c): bool => $c !== '')));
                    if ($line !== '') {
                        $rows[] = '- '.$line;
                    }
                }
                if ($rows !== []) {
                    $blocks[] = ['kind' => 'list', 'text' => implode("\n", $rows)];
                }
            }
        }

        $out = '';
        $previousKind = null;
        foreach ($blocks as $block) {
            if ($out !== '') {
                $out .= ($previousKind === 'list' && $block['kind'] === 'list') ? "\n" : "\n\n";
            }
            $out .= $block['text'];
            $previousKind = $block['kind'];
        }

        return $out;
    }

    /**
     * @param  list<array{kind: string, text: string}>  $blocks
     */
    private function appendParagraph(array &$blocks, DOMElement $paragraph, DOMXPath $xpath, ?string &$title): void
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $this->plainText($paragraph, $xpath)));
        if ($text === '') {
            return;
        }

        $style = (string) $xpath->evaluate('string(./w:pPr/w:pStyle/@w:val)', $paragraph);
        $isTitle = strcasecmp($style, 'Title') === 0;
        $isHeading = preg_match('/^heading\s*([1-9])/i', $style, $level) === 1;

        if ($isTitle || ($isHeading && (int) $level[1] === 1)) {
            if ($title === null) {
                $title = $text;

                return;
            }
        }
        if ($isTitle || $isHeading) {
            $blocks[] = ['kind' => 'heading', 'text' => '## '.$text];

            return;
        }

        $isListItem = (bool) $xpath->evaluate('boolean(./w:pPr/w:numPr)', $paragraph)
            || preg_match('/^list/i', $style) === 1;
        $blocks[] = $isListItem
            ? ['kind' => 'list', 'text' => '- '.$text]
            : ['kind' => 'paragraph', 'text' => $text];
    }

    private function plainText(DOMNode $node, DOMXPath $xpath): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $node) ?: [] as $part) {
            $text .= match ($part->localName) {
                't' => $part->textContent,
                'tab' => ' ',
                default => "\n",
            };
        }

        return $text;
    }

    /** Joins lines that a PDF broke in the middle of a sentence. */
    private static function joinWrappedLines(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $out[] = '';

                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last] !== '' && preg_match('/[.:;!?)]$/u', $out[$last]) !== 1 && preg_match('/^\p{Ll}/u', $line) === 1) {
                $out[$last] .= ' '.$line;
            } else {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
