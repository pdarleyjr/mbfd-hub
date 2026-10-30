<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Builds synthetic TeleStaff roster workbooks. Fake names and 9xxxx IDs only: the repository is
 * public, so the real export never becomes a fixture.
 *
 * Grid layout mirrors the export, starting one column in from the sheet edge:
 * Rank | ID | Name | (gap) | code | From | Through | hours.
 *
 * Roster rows are lists: [rank, id, name, code, from, through, hours]. Use null for a blank cell.
 * A row of the form ['raw' => [offset => value]] writes those cells as they are, for broken rows.
 *
 * Options:
 *  - date (Y-m-d), shift (letter; default from DutyCalendar), division, shiftLabel
 *  - sections: array<title, list<row>>; default coverageSections()
 *  - rowOffset: moves the primary block's title row (default row 15) by this many rows, at least -10
 *  - columnOffset: shifts the whole table right
 *  - spacers: blank rows between sections (default 1); trailingBlanks: rows after the last block
 *  - header / footer: print strings, null to omit; defaults match the export for the date
 *  - generatedAt: "m/d/Y H:i:s" wall clock for the default footer
 *  - otherBlocks: false to write only the primary block
 *  - extraBlock: writes a second block with this shift label and the same division after the primary
 *  - customize: fn (Spreadsheet $book, Worksheet $sheet, int $titleRow): void, before saving
 */
final class TeleStaffRosterWorkbookFactory
{
    public const SECTION_TITLES = [
        'Division Chief / Division Chief 300',
        'Station 1 / Ladder 1',
        'Station 1 / Engine 1',
        'Station 1 / Rescue 1',
        'Station 1 / Rescue 11',
        'Station 2 / Engine 2',
        'Station 2 / Air Truck 810',
        'Station 2 / Rescue 2',
        'Station 2 / Rescue 22',
        'Station 2 / Captain 5',
        'Station 2 / Rescue Float',
        'Station 3 / Ladder 3',
        'Station 3 / Engine 3',
        'Station 3 / Rescue 3',
        'Station 4 / Engine 4',
        'Station 4 / Rescue 4',
        'Station 4 / Rescue 44',
        'Station 6 / Fire Boat 6',
        'Station 6 / Marine Float',
        'Rescue Float Pool / Rescue Float Pool',
        'Combat Float Pool / Combat Float Pool',
    ];

    public const OBSERVED_CODES = [
        'SP', '+OT', 'OT', '2WXON', '2WXEON', "\u{2927}2WXON", "\u{2927}2WXEON",
        'ADONIS DAY', '.ADONIS DAY', 'VACA', 'VAC', '.VACA', '.VAC', 'EVAC',
        '2WXOFF', '2WXEOFF', '.2WXOFF', '.2WXEOFF', "\u{2927}2WXOFF", "\u{2927}2WXEOFF",
        'ADMS', 'MILNP', 'SIC', 'SIC RVT', '.PENFML', '.DAYOFF',
        '^OOC', '^ROC', '^MOC', '^TOC',
        'GWA', 'TRNADM', '.PMS', '+OTD15FI',
    ];

    private const FIRST_COLUMN = 2;

    private const DEFAULT_PRIMARY_TITLE_ROW = 15;

    /** @var list<string> */
    private static array $paths = [];

    /** @param  array<string, mixed>  $options */
    public static function build(array $options = []): string
    {
        $date = (string) ($options['date'] ?? '2026-10-30');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle((string) config('daily_roster.workbook.sheet'));
        $sheet->getProtection()->setSheet(true);

        self::setPrintStrings($sheet, $date, $options);
        $titleRow = self::writeGrid($sheet, $date, $options);

        if (isset($options['customize'])) {
            $options['customize']($book, $sheet, $titleRow);
        }

        $path = self::temporaryPath();
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** One seat per code across all 21 real section titles. */
    public static function coverageSections(): array
    {
        $sections = array_fill_keys(self::SECTION_TITLES, []);
        foreach (self::OBSERVED_CODES as $index => $code) {
            $title = self::SECTION_TITLES[$index % count(self::SECTION_TITLES)];
            $sections[$title][] = self::employee('Firefighter', (string) (90000 + $index), $code);
        }

        return $sections;
    }

    /** @return list<mixed> */
    public static function employee(?string $rank, string|int|float|null $id, ?string $code, string $from = '08:00', string $through = '08:00', int|float $hours = 24, string $name = 'TESTER, ALPHA (CERT1/CERT2)'): array
    {
        return [$rank, $id, $name, $code, $from, $through, $hours];
    }

    /** @return list<mixed> */
    public static function vacancy(?string $rank, string $name = '-,-', string $from = '08:00', string $through = '08:00', int|float $hours = 24): array
    {
        return [$rank, null, $name, null, $from, $through, $hours];
    }

    /** The two command sections, with the given rows appended as their own section. */
    public static function minimalSections(array $rows, string $title = 'Station 1 / Engine 1'): array
    {
        return [
            'Division Chief / Division Chief 300' => [self::employee('Division Chief', '90001', 'SP')],
            'Station 2 / Captain 5' => [self::employee('Captain', '90002', 'SP')],
            $title => $rows,
        ];
    }

    /** Footer print string with the export's shape. */
    public static function footerFor(string $wallClock): string
    {
        return "&CGenerated by Workforce TeleStaff\u{2122}&L{$wallClock}&R&P / &N";
    }

    /** Header print string with the export's shape. Date is m/d/Y. */
    public static function headerFor(string $date): string
    {
        return "&C&BWorkforce TeleStaff&B\n&BRoster Report&B  [{$date}]\n&BLimits&B: Institution: Institution";
    }

    public static function addEntry(string $path, string $name, string $bytes, bool $stored = false): void
    {
        self::withZip($path, static function (ZipArchive $zip) use ($name, $bytes, $stored): void {
            $zip->addFromString($name, $bytes);
            if ($stored) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        });
    }

    /** @param  callable(string): string  $edit */
    public static function editEntry(string $path, string $name, callable $edit): void
    {
        self::withZip($path, static function (ZipArchive $zip) use ($name, $edit): void {
            $bytes = $zip->getFromName($name);
            if ($bytes === false) {
                throw new RuntimeException("Fixture has no entry {$name}");
            }
            $zip->addFromString($name, $edit($bytes));
        });
    }

    public static function setEntryCompression(string $path, string $name, int $method): void
    {
        self::withZip($path, static fn (ZipArchive $zip): bool => $zip->setCompressionName($name, $method));
    }

    public static function encryptEntry(string $path, string $name): void
    {
        self::withZip($path, static fn (ZipArchive $zip): bool => $zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'synthetic-fixture'));
    }

    /**
     * Rewrites an entry's name in the local and central headers, byte for byte. ZipArchive refuses to
     * create exact duplicates and NUL names, so an entry is added under a stand-in of the same length.
     */
    public static function patchEntryName(string $path, string $standIn, string $name): void
    {
        if (strlen($standIn) !== strlen($name)) {
            throw new RuntimeException('Stand-in and name must be the same length');
        }

        file_put_contents($path, str_replace($standIn, $name, (string) file_get_contents($path)));
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$paths = [];
    }

    /** Registers a file the test wrote itself, so cleanup removes it too. */
    public static function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsroster');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary fixture');
        }
        self::$paths[] = $path;

        return $path;
    }

    /** @param  callable(ZipArchive): mixed  $change */
    private static function withZip(string $path, callable $change): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Fixture could not be opened');
        }
        $change($zip);
        if ($zip->close() !== true) {
            throw new RuntimeException('Fixture could not be rewritten');
        }
    }

    /** @param  array<string, mixed>  $options */
    private static function setPrintStrings(Worksheet $sheet, string $date, array $options): void
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        $header = array_key_exists('header', $options) ? $options['header'] : self::headerFor($local->format('m/d/Y'));
        $footer = array_key_exists('footer', $options)
            ? $options['footer']
            : self::footerFor((string) ($options['generatedAt'] ?? $local->format('m/d/Y').' 09:14:15'));

        if ($header !== null) {
            $sheet->getHeaderFooter()->setOddHeader($header);
        }
        if ($footer !== null) {
            $sheet->getHeaderFooter()->setOddFooter($footer);
        }
    }

    /** @param  array<string, mixed>  $options */
    private static function writeGrid(Worksheet $sheet, string $date, array $options): int
    {
        $base = self::FIRST_COLUMN + (int) ($options['columnOffset'] ?? 0);
        $spacers = (int) ($options['spacers'] ?? 1);
        $withOthers = (bool) ($options['otherBlocks'] ?? true);
        $row = 1;

        if ($withOthers) {
            // The title, header, section and spacer rows sit around the data rows.
            $dataRows = self::DEFAULT_PRIMARY_TITLE_ROW - 5 + (int) ($options['rowOffset'] ?? 0);
            $row = self::writeBlock($sheet, $row, $base, 'Chief\'s Office', 'D Shift 4/10,D Shift 5/8', [
                'Chief\'s Office / Administration' => array_map(
                    static fn (int $i): array => self::employee('Fire Chief', (string) (98000 + $i), 'SP', '08:00', '18:00', 10),
                    $dataRows > 0 ? range(1, $dataRows) : [],
                ),
            ], 1);
        }

        $titleRow = $row;
        $shift = (string) ($options['shift'] ?? app(DutyCalendar::class)->shiftFor($date));
        $label = (string) ($options['shiftLabel'] ?? "{$shift} Shift");
        $division = (string) ($options['division'] ?? 'Suppression/Rescue');
        $sections = $options['sections'] ?? self::coverageSections();
        $row = self::writeBlock($sheet, $row, $base, $division, $label, $sections, $spacers);

        if (isset($options['extraBlock'])) {
            $row = self::writeBlock($sheet, $row, $base, $division, (string) $options['extraBlock'], self::minimalSections([]), $spacers);
        }

        if ($withOthers) {
            $row = self::writeBlock($sheet, $row, $base, 'Off Duty Detail', 'Off Duty Detail', [
                'Off Duty Details / Placeholder For Deployment Only' => [self::vacancy('Firefighter', '-,-', '00:00', '00:00', 24)],
                'Off Duty Details / 29 - Synthetic Show' => [self::employee('Inspector', '98100', '+OTD15FI', '19:00', '01:00', 6)],
            ], 1);
        }

        for ($blank = 0; $blank < (int) ($options['trailingBlanks'] ?? 0); $blank++) {
            self::text($sheet, $base + 2, $row + $blank, ' ');
        }

        return $titleRow;
    }

    /** @param  array<string, list<array<mixed>>>  $sections */
    private static function writeBlock(Worksheet $sheet, int $row, int $base, string $division, string $shiftLabel, array $sections, int $spacers): int
    {
        self::text($sheet, $base, $row++, "Miami Beach Fire Department / {$division} / {$shiftLabel}");

        foreach ($sections as $title => $rows) {
            foreach ([0 => 'Rank', 1 => 'ID', 2 => 'Name', 5 => 'From', 6 => 'Through'] as $offset => $label) {
                self::text($sheet, $base + $offset, $row, $label);
            }
            $row++;
            self::text($sheet, $base, $row++, (string) $title);

            foreach ($rows as $entry) {
                self::writeRosterRow($sheet, $row++, $base, $entry);
            }
            for ($spacer = 0; $spacer < $spacers; $spacer++) {
                self::text($sheet, $base + 2, $row++, ' ');
            }
        }

        return $row;
    }

    /** @param  array<int|string, mixed>  $entry */
    private static function writeRosterRow(Worksheet $sheet, int $row, int $base, array $entry): void
    {
        if (isset($entry['raw'])) {
            foreach ($entry['raw'] as $offset => $value) {
                self::cell($sheet, $base + (int) $offset, $row, $value);
            }

            return;
        }

        [$rank, $id, $name, $code, $from, $through, $hours] = $entry;
        foreach ([0 => $rank, 1 => $id, 2 => $name, 4 => $code, 5 => $from, 6 => $through, 7 => $hours] as $offset => $value) {
            self::cell($sheet, $base + $offset, $row, $value);
        }
    }

    private static function cell(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $coordinate = Coordinate::stringFromColumnIndex($column).$row;
        if (is_int($value) || is_float($value)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);

            return;
        }

        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    private static function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        self::cell($sheet, $column, $row, $value);
    }
}
