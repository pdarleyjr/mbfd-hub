<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

use Closure;
use XMLReader;
use ZipArchive;

/**
 * Proves an emailed workbook is the plain, formula-free TeleStaff package before any spreadsheet
 * library touches it, and reads the print header and footer that hold the duty date and export time.
 *
 * Every failure throws a TeleStaffWorkbookException carrying a code, never package or cell text.
 */
final class TeleStaffWorkbookPreflight
{
    private const SIGNATURE = "PK\x03\x04";

    private const END_OF_CENTRAL_DIRECTORY = "PK\x05\x06";

    private const CENTRAL_FILE_HEADER = "PK\x01\x02";

    private const WORKBOOK_PART = 'xl/workbook.xml';

    private const WORKBOOK_RELS_PART = 'xl/_rels/workbook.xml.rels';

    private const CONTENT_TYPES_PART = '[Content_Types].xml';

    private const WORKBOOK_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const WORKSHEET_PATTERN = '#^xl/worksheets/sheet\d+\.xml$#D';

    /** @var list<string> */
    private const ALLOWED_PARTS = [
        '#^\[Content_Types\]\.xml$#D',
        '#^_rels/\.rels$#D',
        '#^docProps/(?:app|core)\.xml$#D',
        '#^xl/workbook\.xml$#D',
        '#^xl/_rels/workbook\.xml\.rels$#D',
        '#^xl/sharedStrings\.xml$#D',
        '#^xl/styles\.xml$#D',
        '#^xl/theme/[^/]+$#D',
        self::WORKSHEET_PATTERN,
        '#^xl/worksheets/_rels/sheet\d+\.xml\.rels$#D',
        '#^xl/drawings/[^/]+$#D',
        '#^xl/drawings/_rels/[^/]+$#D',
        '#^xl/media/image\d+\.png$#D',
        '#^xl/printerSettings/[^/]+\.bin$#D',
    ];

    private const READ_CHUNK_BYTES = 65536;

    /** @return array{sheetPart: string, oddHeader: ?string, oddFooter: ?string} */
    public function inspect(string $absolutePath): array
    {
        $limits = (array) config('daily_roster.workbook');
        $this->assertCentralDirectorySafe($this->readFile($absolutePath, (int) $limits['max_bytes']), (int) $limits['max_entries']);

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }

        try {
            $parts = $this->readParts($zip, $limits);
        } finally {
            $zip->close();
        }

        $this->assertWorkbookContentType($parts);
        $sheetPart = $this->resolveSheetPart($parts, (string) $limits['sheet']);

        return ['sheetPart' => $sheetPart, ...$this->inspectSheet($parts[$sheetPart], (int) $limits['max_rows'])];
    }

    private function readFile(string $path, int $maxBytes): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new TeleStaffWorkbookException('read_failed');
        }
        if ((int) filesize($path) > $maxBytes) {
            throw new TeleStaffWorkbookException('file_too_large');
        }

        $bytes = file_get_contents($path, false, null, 0, $maxBytes + 1);
        if ($bytes === false) {
            throw new TeleStaffWorkbookException('read_failed');
        }
        if (strlen($bytes) > $maxBytes) {
            throw new TeleStaffWorkbookException('file_too_large');
        }
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }

        return $bytes;
    }

    /**
     * Walks the central directory from the raw bytes. PHP's zip API cuts names at a NUL and hides
     * exact duplicates behind its name index, so those can only be seen here.
     */
    private function assertCentralDirectorySafe(string $bytes, int $maxEntries): void
    {
        $end = strrpos($bytes, self::END_OF_CENTRAL_DIRECTORY);
        if ($end === false || strlen($bytes) < $end + 22) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }

        ['count' => $count, 'offset' => $offset] = unpack('vcount/x4/Voffset', substr($bytes, $end + 10, 10));
        if ($count === 0xFFFF || $offset === 0xFFFFFFFF) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }
        if ($count > $maxEntries) {
            throw new TeleStaffWorkbookException('zip_limit_exceeded');
        }

        $seen = [];
        for ($index = 0; $index < $count; $index++) {
            [$name, $offset] = $this->readCentralEntry($bytes, $offset, $end);
            $folded = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$folded])) {
                throw new TeleStaffWorkbookException('zip_entry_unsafe');
            }
            $seen[$folded] = true;
        }
    }

    /** @return array{0: string, 1: int} The entry name and the offset of the next entry */
    private function readCentralEntry(string $bytes, int $offset, int $limit): array
    {
        if ($offset + 46 > $limit || substr($bytes, $offset, 4) !== self::CENTRAL_FILE_HEADER) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }

        [
            'flags' => $flags,
            'name' => $nameLength,
            'extra' => $extraLength,
            'comment' => $commentLength,
        ] = unpack('vflags', substr($bytes, $offset + 8, 2)) + unpack('vname/vextra/vcomment', substr($bytes, $offset + 28, 6));

        $next = $offset + 46 + $nameLength + $extraLength + $commentLength;
        if ($next > $limit) {
            throw new TeleStaffWorkbookException('not_a_zip');
        }

        $name = substr($bytes, $offset + 46, $nameLength);
        if (($flags & 1) === 1 || ! $this->isSafeName($name)) {
            throw new TeleStaffWorkbookException('zip_entry_unsafe');
        }

        return [$name, $next];
    }

    private function isSafeName(string $name): bool
    {
        return $name !== ''
            && ! str_contains($name, "\0")
            && ! str_contains($name, '\\')
            && ! str_starts_with($name, '/')
            && ! in_array('..', explode('/', $name), true);
    }

    /**
     * Reads every entry with bounded streams. Declared sizes are compared to what actually came out,
     * never trusted. Returns only the parts later checks need.
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, string>
     */
    private function readParts(ZipArchive $zip, array $limits): array
    {
        $parts = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new TeleStaffWorkbookException('read_failed');
            }
            $this->assertEntryAllowed($stat);

            $bytes = $this->readEntry($zip, $stat, $limits, $total);
            if ($bytes !== null) {
                $this->assertNoDoctype($bytes);
                if (str_ends_with($stat['name'], '.rels')) {
                    $this->assertNoExternalRelationships($bytes);
                }
                if ($this->isRetained($stat['name'])) {
                    $parts[$stat['name']] = $bytes;
                }
            }
        }

        return $parts;
    }

    /** @param  array<string, mixed>  $stat */
    private function assertEntryAllowed(array $stat): void
    {
        $name = (string) $stat['name'];
        if (! $this->isSafeName($name)
            || ! in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
            || ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
            throw new TeleStaffWorkbookException('zip_entry_unsafe');
        }

        foreach (self::ALLOWED_PARTS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return;
            }
        }

        throw new TeleStaffWorkbookException('part_not_allowed');
    }

    /**
     * @param  array<string, mixed>  $stat
     * @param  array<string, mixed>  $limits
     * @return string|null The bytes of a text part; null for binary parts, which are only counted
     */
    private function readEntry(ZipArchive $zip, array $stat, array $limits, int &$total): ?string
    {
        $stream = $zip->getStream((string) $stat['name']);
        if ($stream === false) {
            throw new TeleStaffWorkbookException('read_failed');
        }

        $keep = ! $this->isBinary((string) $stat['name']);
        $buffer = '';
        $size = 0;
        $maxByRatio = max(1, (int) $stat['comp_size']) * (int) $limits['max_entry_ratio'];

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::READ_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new TeleStaffWorkbookException('read_failed');
                }
                $size += strlen($chunk);
                $total += strlen($chunk);
                if ($size > (int) $limits['max_entry_uncompressed_bytes']
                    || $total > (int) $limits['max_total_uncompressed_bytes']
                    || $size > $maxByRatio) {
                    throw new TeleStaffWorkbookException('zip_limit_exceeded');
                }
                if ($keep) {
                    $buffer .= $chunk;
                }
            }
        } finally {
            fclose($stream);
        }

        if ($size !== (int) $stat['size']) {
            throw new TeleStaffWorkbookException('zip_entry_unsafe');
        }

        return $keep ? $buffer : null;
    }

    private function isBinary(string $name): bool
    {
        return str_ends_with($name, '.png') || str_ends_with($name, '.bin');
    }

    private function isRetained(string $name): bool
    {
        return in_array($name, [self::CONTENT_TYPES_PART, self::WORKBOOK_PART, self::WORKBOOK_RELS_PART], true)
            || preg_match(self::WORKSHEET_PATTERN, $name) === 1;
    }

    /** Null bytes are dropped first so a UTF-16 declaration cannot hide from the search. */
    private function assertNoDoctype(string $bytes): void
    {
        $view = str_replace("\0", '', $bytes);
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $byteOrderMark) {
            if (str_starts_with($view, $byteOrderMark)) {
                $view = substr($view, strlen($byteOrderMark));
                break;
            }
        }

        $view = ltrim($view);
        if ($view === '') {
            return;
        }
        if ($view[0] !== '<' || stripos($view, '<!doctype') !== false) {
            throw new TeleStaffWorkbookException('doctype_rejected');
        }
    }

    private function assertNoExternalRelationships(string $bytes): void
    {
        $this->walkXml($bytes, static function (XMLReader $reader): void {
            if ($reader->localName !== 'Relationship') {
                return;
            }
            $target = (string) $reader->getAttribute('Target');
            if (strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0
                || preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//|\\\\)#i', $target) === 1) {
                throw new TeleStaffWorkbookException('external_relationship');
            }
        });
    }

    /** @param  array<string, string>  $parts */
    private function assertWorkbookContentType(array $parts): void
    {
        $contentType = null;
        $this->walkXml($this->requiredPart($parts, self::CONTENT_TYPES_PART), static function (XMLReader $reader) use (&$contentType): void {
            if ($reader->localName === 'Override' && $reader->getAttribute('PartName') === '/'.self::WORKBOOK_PART) {
                $contentType = $reader->getAttribute('ContentType');
            }
        });

        if ($contentType !== self::WORKBOOK_CONTENT_TYPE) {
            throw new TeleStaffWorkbookException('part_not_allowed');
        }
    }

    /** @param  array<string, string>  $parts */
    private function resolveSheetPart(array $parts, string $expectedName): string
    {
        $sheets = [];
        $this->walkXml($this->requiredPart($parts, self::WORKBOOK_PART), static function (XMLReader $reader) use (&$sheets): void {
            if ($reader->localName === 'sheet') {
                $sheets[] = [
                    'name' => $reader->getAttribute('name'),
                    'id' => $reader->getAttributeNs('id', self::RELATIONSHIPS_NAMESPACE),
                ];
            }
        });

        $worksheets = preg_grep(self::WORKSHEET_PATTERN, array_keys($parts));
        if (count($sheets) !== 1 || $sheets[0]['name'] !== $expectedName || count($worksheets) !== 1) {
            throw new TeleStaffWorkbookException('sheet_mismatch');
        }

        $target = null;
        $this->walkXml($this->requiredPart($parts, self::WORKBOOK_RELS_PART), static function (XMLReader $reader) use (&$target, $sheets): void {
            if ($reader->localName === 'Relationship' && $reader->getAttribute('Id') === $sheets[0]['id']) {
                $target = (string) $reader->getAttribute('Target');
            }
        });

        $part = str_starts_with((string) $target, '/') ? ltrim((string) $target, '/') : 'xl/'.$target;
        if (! in_array($part, $worksheets, true)) {
            throw new TeleStaffWorkbookException('sheet_mismatch');
        }

        return $part;
    }

    /** @return array{oddHeader: ?string, oddFooter: ?string} */
    private function inspectSheet(string $bytes, int $maxRows): array
    {
        $found = ['oddHeader' => null, 'oddFooter' => null];
        $rows = 0;
        $this->walkXml($bytes, static function (XMLReader $reader) use (&$found, &$rows, $maxRows): void {
            switch ($reader->localName) {
                case 'f':
                    throw new TeleStaffWorkbookException('formula_rejected');
                case 'c':
                    if ($reader->getAttribute('t') === 'e') {
                        throw new TeleStaffWorkbookException('formula_rejected');
                    }
                    break;
                case 'row':
                    $rows++;
                    if ($rows > $maxRows || (int) $reader->getAttribute('r') > $maxRows) {
                        throw new TeleStaffWorkbookException('zip_limit_exceeded');
                    }
                    break;
                case 'oddHeader':
                case 'oddFooter':
                    $found[$reader->localName] ??= $reader->readString();
                    break;
            }
        });

        return $found;
    }

    /** @param  array<string, string>  $parts */
    private function requiredPart(array $parts, string $name): string
    {
        return $parts[$name] ?? throw new TeleStaffWorkbookException('read_failed');
    }

    /** @param  Closure(XMLReader): void  $onElement */
    private function walkXml(string $bytes, Closure $onElement): void
    {
        if ($bytes === '') {
            throw new TeleStaffWorkbookException('read_failed');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if ($reader->XML($bytes, null, LIBXML_NONET) !== true) {
                throw new TeleStaffWorkbookException('read_failed');
            }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $onElement($reader);
                }
            }
            $error = libxml_get_last_error();
            if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
                throw new TeleStaffWorkbookException('read_failed');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
