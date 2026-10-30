<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

use App\Support\Captain5\DutyCalendar;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Turns a trusted TeleStaff `Dailyroster.xlsx` into a normalized report, or fails closed.
 *
 * Positions come from labels and row shapes, never from fixed rows or column letters. Only the
 * primary division block is validated; other blocks are counted. Exceptions carry codes and row
 * numbers only, because the cell text is untrusted member data.
 */
final class TeleStaffWorkbookParser
{
    public const PARSER_VERSION = 'xlsx-1';

    private const DUTY_DAY_START = '08:00';

    private const TITLE_ORGANIZATION = 'Miami Beach Fire Department';

    /** @var list<string> */
    private const COLUMN_LABELS = ['Rank', 'ID', 'Name', 'From', 'Through'];

    /** @var array<string, 'vacancy_suppressed'|'vacancy_open'> */
    private const VACANCY_KINDS = ['-,-' => 'vacancy_suppressed', '?,?' => 'vacancy_open'];

    private const ID_PATTERN = '/^\d{4,6}$/D';

    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';

    private const MAX_CELL_CHARACTERS = 512;

    private const HOURS_TOLERANCE = 0.05;

    private const MAX_DAYS_FROM_GENERATED = 2;

    public function __construct(
        private readonly DutyCalendar $calendar = new DutyCalendar,
        private readonly TeleStaffWorkbookPreflight $preflight = new TeleStaffWorkbookPreflight,
    ) {}

    /** @throws TeleStaffWorkbookException */
    public function parse(string $absolutePath, string $sourceSha256): TeleStaffRosterReport
    {
        $package = $this->preflight->inspect($absolutePath);
        $dutyDate = $this->dutyDate($package['oddHeader']);
        [$generatedAt, $warnings] = $this->generatedAt($package['oddFooter'], $dutyDate);

        $sheetName = (string) config('daily_roster.workbook.sheet');
        $blocks = $this->splitBlocks($this->readRows($absolutePath, $sheetName));
        $primary = $this->primaryBlock($blocks);
        $shift = $this->shift($primary, $dutyDate);

        [$windowStart, $windowEnd] = $this->calendar->window($dutyDate);
        $parsed = $this->parsePrimaryBlock($primary, $dutyDate, $windowStart, $windowEnd);
        $this->assertCommandSections($parsed['sections']);

        return new TeleStaffRosterReport(
            parserVersion: self::PARSER_VERSION,
            sourceSha256: $sourceSha256,
            sheetName: $sheetName,
            dutyDate: $dutyDate,
            shift: $shift,
            windowStartAt: $windowStart->toIso8601ZuluString(),
            windowEndAt: $windowEnd->toIso8601ZuluString(),
            generatedAt: $generatedAt,
            divisionTitle: $primary['title'],
            titleRow: $primary['titleRow'],
            layoutFingerprint: $this->layoutFingerprint($parsed['headerCells']),
            sections: $parsed['sections'],
            otherBlocks: $this->summarizeOtherBlocks($blocks, $primary['titleRow']),
            warnings: [...$warnings, ...$parsed['warnings']],
        );
    }

    private function dutyDate(?string $header): string
    {
        $pattern = '/Roster Report(?:&[A-Za-z])*\s*\[(\d{2})\/(\d{2})\/(\d{4})\]/';
        if ($header === null || preg_match_all($pattern, $header, $matches, PREG_SET_ORDER) !== 1) {
            throw new TeleStaffWorkbookException('date_missing');
        }

        [, $month, $day, $year] = $matches[0];
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new TeleStaffWorkbookException('date_missing');
        }

        return "{$year}-{$month}-{$day}";
    }

    /** @return array{0: string, 1: list<string>} The UTC export time and any warnings */
    private function generatedAt(?string $footer, string $dutyDate): array
    {
        $pattern = '/&L\s*(\d{2})\/(\d{2})\/(\d{4}) ([01]\d|2[0-3]):([0-5]\d):([0-5]\d)\s*(?=&|$)/D';
        if ($footer === null || preg_match($pattern, $footer, $match) !== 1 || ! checkdate((int) $match[1], (int) $match[2], (int) $match[3])) {
            throw new TeleStaffWorkbookException('generated_missing');
        }

        $wallClock = "{$match[3]}-{$match[1]}-{$match[2]} {$match[4]}:{$match[5]}:{$match[6]}";
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $wallClock, HubTimezone::NAME);

        $days = abs(CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate, 'UTC')
            ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), 'UTC'), false));
        if ($days > self::MAX_DAYS_FROM_GENERATED) {
            throw new TeleStaffWorkbookException('generated_far_from_date');
        }

        return [
            $local->utc()->toIso8601ZuluString(),
            $this->isAmbiguousWallClock($local, $wallClock) ? ['generated_time_ambiguous'] : [],
        ];
    }

    /** During the fall-back hour the same wall clock exists twice, one hour apart. */
    private function isAmbiguousWallClock(CarbonImmutable $local, string $wallClock): bool
    {
        foreach ([3600, -3600] as $offset) {
            $other = CarbonImmutable::createFromTimestamp($local->getTimestamp() + $offset, HubTimezone::NAME);
            if ($other->format('Y-m-d H:i:s') === $wallClock) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<int, string|int|float|bool>> Non-blank cells by row, then column index */
    private function readRows(string $path, string $sheetName): array
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setLoadSheetsOnly([$sheetName]);

        try {
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new TeleStaffWorkbookException('read_failed');
        }

        try {
            $sheet = $book->getSheetByName($sheetName) ?? throw new TeleStaffWorkbookException('sheet_mismatch');

            return $this->collectCells($sheet);
        } catch (TeleStaffWorkbookException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TeleStaffWorkbookException('read_failed');
        } finally {
            $book->disconnectWorksheets();
            unset($book);
        }
    }

    /** @return array<int, array<int, string|int|float|bool>> */
    private function collectCells(Worksheet $sheet): array
    {
        $maxRows = (int) config('daily_roster.workbook.max_rows');
        $rows = [];
        foreach ($sheet->getCoordinates() as $coordinate) {
            [$column, $row] = Coordinate::coordinateFromString($coordinate);
            if ((int) $row > $maxRows) {
                throw new TeleStaffWorkbookException('zip_limit_exceeded');
            }

            $value = $this->cellValue($sheet->getCell($coordinate)->getValue());
            if ($value !== null) {
                $rows[(int) $row][Coordinate::columnIndexFromString($column)] = $value;
            }
        }
        ksort($rows);

        return array_map(static function (array $cells): array {
            ksort($cells);

            return $cells;
        }, $rows);
    }

    /** Text is trimmed, including the non-breaking spaces of the export. Blank becomes null. */
    private function cellValue(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value)) {
            $text = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);
            if ($text === null) {
                throw new TeleStaffWorkbookException('read_failed');
            }

            return $text === '' ? null : $text;
        }

        return is_int($value) || is_float($value) || is_bool($value) ? $value : null;
    }

    /**
     * @param  array<int, array<int, string|int|float|bool>>  $rows
     * @return list<array{titleRow: int, title: string, division: string, shiftLabel: string, rows: array<int, array<int, string|int|float|bool>>}>
     */
    private function splitBlocks(array $rows): array
    {
        $blocks = [];
        $current = -1;
        foreach ($rows as $rowNumber => $cells) {
            $title = $this->blockTitle($cells);
            if ($title !== null) {
                $blocks[++$current] = ['titleRow' => $rowNumber, ...$title, 'rows' => []];
            } elseif ($current >= 0) {
                $blocks[$current]['rows'][$rowNumber] = $cells;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<int, string|int|float|bool>  $cells
     * @return array{title: string, division: string, shiftLabel: string}|null
     */
    private function blockTitle(array $cells): ?array
    {
        $text = count($cells) === 1 ? reset($cells) : null;
        if (! is_string($text)) {
            return null;
        }

        $parts = array_map('trim', explode(' / ', $text, 3));
        if (count($parts) !== 3 || $parts[0] !== self::TITLE_ORGANIZATION || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return ['title' => $text, 'division' => $parts[1], 'shiftLabel' => $parts[2]];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function primaryBlock(array $blocks): array
    {
        $division = (string) config('daily_roster.workbook.division');
        $matches = array_values(array_filter($blocks, static fn (array $block): bool => $block['division'] === $division));

        return match (count($matches)) {
            0 => throw new TeleStaffWorkbookException('block_not_found'),
            1 => $matches[0],
            default => throw new TeleStaffWorkbookException('block_ambiguous', $matches[1]['titleRow']),
        };
    }

    /** @param  array<string, mixed>  $primary */
    private function shift(array $primary, string $dutyDate): string
    {
        if (preg_match('/^([ABC]) Shift$/D', $primary['shiftLabel'], $match) !== 1) {
            throw new TeleStaffWorkbookException('shift_unreadable', $primary['titleRow']);
        }
        if ($this->calendar->shiftFor($dutyDate) !== $match[1]) {
            throw new TeleStaffWorkbookException('shift_calendar_mismatch', $primary['titleRow']);
        }

        return $match[1];
    }

    /**
     * @param  array<string, mixed>  $primary
     * @return array{sections: list<TeleStaffRosterSection>, warnings: list<string>, headerCells: array<int, string|int|float|bool>}
     */
    private function parsePrimaryBlock(array $primary, string $dutyDate, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $columns = null;
        $headerCells = [];
        $expectSection = false;
        $lastHeaderRow = $primary['titleRow'];
        $sections = [];
        $warnings = [];

        foreach ($primary['rows'] as $rowNumber => $cells) {
            $this->assertCellLengths($rowNumber, $cells);

            if ($this->isHeaderCandidate($cells)) {
                $found = $this->columnsFromHeader($rowNumber, $cells);
                if ($expectSection || ($columns !== null && $found !== $columns)) {
                    throw new TeleStaffWorkbookException($expectSection ? 'row_unrecognized' : 'header_unrecognized', $rowNumber);
                }
                [$columns, $headerCells, $expectSection, $lastHeaderRow] = [$found, $cells, true, $rowNumber];

                continue;
            }

            if ($columns === null) {
                throw new TeleStaffWorkbookException('header_unrecognized', $rowNumber);
            }

            if ($expectSection) {
                $sections[] = $this->openSection($rowNumber, $cells, $columns);
                $expectSection = false;

                continue;
            }

            $sectionIndex = array_key_last($sections);
            $row = $this->dataRow($rowNumber, $cells, $columns, $dutyDate, $windowStart, $windowEnd);
            $sections[$sectionIndex]['seats'] = $this->placeInSeat($sections[$sectionIndex]['seats'], $rowNumber, $row);
            if ($row['entry']->availability === 'unknown') {
                $warnings[] = "unknown_code:{$rowNumber}";
            }
        }

        if ($columns === null) {
            throw new TeleStaffWorkbookException('header_unrecognized', $primary['titleRow']);
        }
        if ($expectSection) {
            throw new TeleStaffWorkbookException('row_unrecognized', $lastHeaderRow);
        }

        return [...$this->buildSections($sections, $warnings), 'headerCells' => $headerCells];
    }

    /** @param  array<int, string|int|float|bool>  $cells */
    private function assertCellLengths(int $rowNumber, array $cells): void
    {
        foreach ($cells as $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_CELL_CHARACTERS) {
                throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
            }
        }
    }

    /** @param  array<int, string|int|float|bool>  $cells */
    private function isHeaderCandidate(array $cells): bool
    {
        return in_array(self::COLUMN_LABELS[0], $cells, true);
    }

    /**
     * Code and hours are unlabelled: they are the cells beside From and Through.
     *
     * @param  array<int, string|int|float|bool>  $cells
     * @return array{rank: int, id: int, name: int, code: int, from: int, through: int, hours: int}
     */
    private function columnsFromHeader(int $rowNumber, array $cells): array
    {
        $found = array_flip(array_filter($cells, 'is_string'));
        $ordered = array_map(static fn (string $label): ?int => $found[$label] ?? null, self::COLUMN_LABELS);
        $sorted = $ordered;
        sort($sorted);

        if (count($cells) !== count(self::COLUMN_LABELS) || in_array(null, $ordered, true) || $sorted !== $ordered) {
            throw new TeleStaffWorkbookException('header_unrecognized', $rowNumber);
        }

        [$rank, $id, $name, $from, $through] = $ordered;
        if ($from - 1 <= $name) {
            throw new TeleStaffWorkbookException('header_unrecognized', $rowNumber);
        }

        return ['rank' => $rank, 'id' => $id, 'name' => $name, 'code' => $from - 1, 'from' => $from, 'through' => $through, 'hours' => $through + 1];
    }

    /**
     * @param  array<int, string|int|float|bool>  $cells
     * @param  array<string, int>  $columns
     * @return array{row: int, title: string, parent: string, unit: string, seats: list<array<string, mixed>>}
     */
    private function openSection(int $rowNumber, array $cells, array $columns): array
    {
        $title = $cells[$columns['rank']] ?? null;
        if (count($cells) !== 1 || ! is_string($title)) {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        $parts = array_map('trim', explode(' / ', $title, 2));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        return ['row' => $rowNumber, 'title' => $title, 'parent' => $parts[0], 'unit' => $parts[1], 'seats' => []];
    }

    /**
     * @param  array<int, string|int|float|bool>  $cells
     * @param  array<string, int>  $columns
     * @return array{rank: string, entry: TeleStaffRosterEntry}
     */
    private function dataRow(int $rowNumber, array $cells, array $columns, string $dutyDate, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        if (array_diff_key($cells, array_flip($columns)) !== []) {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        $rank = $cells[$columns['rank']] ?? '';
        $name = $cells[$columns['name']] ?? null;
        $code = $cells[$columns['code']] ?? '';
        $from = $cells[$columns['from']] ?? null;
        $through = $cells[$columns['through']] ?? null;
        $hours = $cells[$columns['hours']] ?? null;
        if (! is_string($rank) || ! is_string($name) || ! is_string($code)
            || ! is_string($from) || preg_match(self::TIME_PATTERN, $from) !== 1
            || ! is_string($through) || preg_match(self::TIME_PATTERN, $through) !== 1
            || ! is_numeric($hours)) {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        $id = $cells[$columns['id']] ?? null;
        // Two decimals is what the snapshot stores, so the content hash survives the database round trip.
        $reportedHours = round((float) $hours, 2);
        [$startAt, $endAt] = $this->interval($rowNumber, $dutyDate, $from, $through, $reportedHours, $windowStart, $windowEnd);
        $interval = ['rawFrom' => $from, 'rawThrough' => $through, 'reportedHours' => $reportedHours, 'startAt' => $startAt, 'endAt' => $endAt];

        $entry = isset(self::VACANCY_KINDS[$name])
            ? $this->vacancyEntry($rowNumber, $rank, $name, $id, $code, $interval)
            : $this->employeeEntry($rowNumber, $rank, $name, $id, $code, $interval);

        return ['rank' => $rank, 'entry' => $entry];
    }

    /** @param  array{rawFrom: string, rawThrough: string, reportedHours: float, startAt: ?string, endAt: ?string}  $interval */
    private function vacancyEntry(int $rowNumber, string $rank, string $name, mixed $id, string $code, array $interval): TeleStaffRosterEntry
    {
        if ($id !== null) {
            throw new TeleStaffWorkbookException('vacancy_has_id', $rowNumber);
        }
        if ($code !== '') {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        return new TeleStaffRosterEntry(
            sourceRow: $rowNumber,
            isContinuation: $rank === '',
            kind: self::VACANCY_KINDS[$name],
            employeeId: null,
            rawName: $name,
            displayName: null,
            certifications: [],
            rawCode: null,
            baseCode: null,
            prefix: null,
            availability: 'vacant',
            category: null,
            displayLabel: null,
            rawFrom: $interval['rawFrom'],
            rawThrough: $interval['rawThrough'],
            reportedHours: $interval['reportedHours'],
            startAt: $interval['startAt'],
            endAt: $interval['endAt'],
            reviewReasons: [],
        );
    }

    /** @param  array{rawFrom: string, rawThrough: string, reportedHours: float, startAt: ?string, endAt: ?string}  $interval */
    private function employeeEntry(int $rowNumber, string $rank, string $name, mixed $id, string $code, array $interval): TeleStaffRosterEntry
    {
        $employeeId = $this->employeeId($rowNumber, $id);
        if ($code === '') {
            throw new TeleStaffWorkbookException('id_without_code', $rowNumber);
        }

        [$displayName, $certifications] = $this->nameParts($rowNumber, $name);
        $classification = TeleStaffCodebook::classify($code);

        return new TeleStaffRosterEntry(
            sourceRow: $rowNumber,
            isContinuation: $rank === '',
            kind: 'employee',
            employeeId: $employeeId,
            rawName: $name,
            displayName: $displayName,
            certifications: $certifications,
            rawCode: $classification->rawCode,
            baseCode: $classification->baseCode,
            prefix: $classification->prefix,
            availability: $classification->availability,
            category: $classification->category,
            displayLabel: $classification->displayLabel,
            rawFrom: $interval['rawFrom'],
            rawThrough: $interval['rawThrough'],
            reportedHours: $interval['reportedHours'],
            startAt: $interval['startAt'],
            endAt: $interval['endAt'],
            reviewReasons: $classification->availability === 'unknown' ? ['unknown_code'] : [],
        );
    }

    /** IDs can be typed as numbers in the cell; only integer values normalize to text. */
    private function employeeId(int $rowNumber, mixed $id): string
    {
        $text = match (true) {
            is_string($id) => $id,
            is_int($id) => (string) $id,
            is_float($id) && is_finite($id) && $id === floor($id) && abs($id) < 1e9 => sprintf('%d', $id),
            default => '',
        };

        if (preg_match(self::ID_PATTERN, $text) !== 1) {
            throw new TeleStaffWorkbookException('id_malformed', $rowNumber);
        }

        return $text;
    }

    /** @return array{0: string, 1: list<string>} `LAST, FIRST (CERT/CERT)` as "First Last" and the certifications */
    private function nameParts(int $rowNumber, string $raw): array
    {
        $body = $raw;
        $certifications = [];
        if (preg_match('/^(.*?)\s*\(([^()]*)\)$/su', $raw, $match) === 1) {
            $body = $match[1];
            $certifications = array_values(array_filter(array_map('trim', explode('/', $match[2])), static fn (string $cert): bool => $cert !== ''));
        }

        $parts = array_map('trim', explode(',', $body, 2));
        $ordered = count($parts) === 2 ? "{$parts[1]} {$parts[0]}" : $parts[0];
        $display = $this->titleCaseName((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $ordered));
        if ($display === '') {
            throw new TeleStaffWorkbookException('row_unrecognized', $rowNumber);
        }

        return [$display, $certifications];
    }

    private function titleCaseName(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $word): string => $this->titleCaseWord($word), $words));
    }

    /** Roman suffixes stay upper case; hyphenated and apostrophe parts each get a capital. */
    private function titleCaseWord(string $word): string
    {
        if (in_array(mb_strtoupper(rtrim($word, '.')), ['II', 'III', 'IV'], true)) {
            return mb_strtoupper($word);
        }

        $pieces = preg_split("/([-'\x{2019}])/u", mb_strtolower($word), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(
            static fn (string $piece): string => mb_strtoupper(mb_substr($piece, 0, 1)).mb_substr($piece, 1),
            $pieces,
        ));
    }

    /**
     * Wall clock on the duty date: a From before the duty day start belongs to the next morning, and
     * a Through at or before From is the following day.
     *
     * @return array{0: string, 1: string} UTC start and end
     */
    private function interval(int $rowNumber, string $dutyDate, string $from, string $through, float $hours, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $startDate = $from < self::DUTY_DAY_START ? $this->nextDate($dutyDate) : $dutyDate;
        $endDate = $through <= $from ? $this->nextDate($startDate) : $startDate;
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$startDate} {$from}", HubTimezone::NAME);
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$endDate} {$through}", HubTimezone::NAME);

        if ($start->lessThan($windowStart) || $end->greaterThan($windowEnd)) {
            throw new TeleStaffWorkbookException('interval_outside_window', $rowNumber);
        }

        $elapsed = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        $wallClock = $elapsed + ($end->getOffset() - $start->getOffset()) / 3600;
        if (abs($elapsed - $hours) > self::HOURS_TOLERANCE && abs($wallClock - $hours) > self::HOURS_TOLERANCE) {
            throw new TeleStaffWorkbookException('hours_mismatch', $rowNumber);
        }

        return [$start->utc()->toIso8601ZuluString(), $end->utc()->toIso8601ZuluString()];
    }

    private function nextDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->addDay()->format('Y-m-d');
    }

    /**
     * A non-empty rank always opens a new seat, even when identical to the one above.
     *
     * @param  list<array<string, mixed>>  $seats
     * @param  array{rank: string, entry: TeleStaffRosterEntry}  $row
     * @return list<array<string, mixed>>
     */
    private function placeInSeat(array $seats, int $rowNumber, array $row): array
    {
        if ($row['rank'] !== '') {
            return [...$seats, ['row' => $rowNumber, 'rank' => $row['rank'], 'entries' => [$row['entry']]]];
        }
        if ($seats === []) {
            throw new TeleStaffWorkbookException('seat_without_rank', $rowNumber);
        }

        $seats[array_key_last($seats)]['entries'][] = $row['entry'];

        return $seats;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<string>  $warnings
     * @return array{sections: list<TeleStaffRosterSection>, warnings: list<string>}
     */
    private function buildSections(array $sections, array $warnings): array
    {
        $built = [];
        foreach ($sections as $sectionOrder => $section) {
            if ($section['seats'] === []) {
                $warnings[] = "empty_section:{$section['row']}";
            }

            $seats = [];
            foreach ($section['seats'] as $seatOrder => $seat) {
                $seats[] = new TeleStaffRosterSeat($seatOrder, $seat['row'], $seat['rank'], $seat['entries']);
                $warnings = [...$warnings, ...$this->overlapWarnings($seat['entries'])];
            }

            $built[] = new TeleStaffRosterSection($sectionOrder, $section['row'], $section['title'], $section['parent'], $section['unit'], $seats);
        }

        return ['sections' => $built, 'warnings' => $warnings];
    }

    /**
     * A seat's staffed rows should tile the day. Annotations and absences (leading or not) sit on
     * top of a seat by design, so only working and vacant rows can collide.
     *
     * @param  list<TeleStaffRosterEntry>  $entries
     * @return list<string>
     */
    private function overlapWarnings(array $entries): array
    {
        $staffed = array_values(array_filter($entries, static fn (TeleStaffRosterEntry $entry): bool => in_array($entry->availability, ['working', 'vacant'], true)));
        usort($staffed, static fn (TeleStaffRosterEntry $a, TeleStaffRosterEntry $b): int => $a->startAt <=> $b->startAt);

        $warnings = [];
        $latestEnd = null;
        foreach ($staffed as $entry) {
            if ($latestEnd !== null && $entry->startAt < $latestEnd) {
                $warnings[] = "seat_overlap:{$entry->sourceRow}";
            }
            $latestEnd = max($latestEnd ?? $entry->endAt, $entry->endAt);
        }

        return $warnings;
    }

    /** @param  list<TeleStaffRosterSection>  $sections */
    private function assertCommandSections(array $sections): void
    {
        $units = array_map(static fn (TeleStaffRosterSection $section): string => $section->unit, $sections);
        foreach (['command_300' => 'section_missing_command_300', 'captain_5' => 'section_missing_captain_5'] as $role => $errorCode) {
            if (! in_array(trim((string) config("daily_roster.section_roles.{$role}")), $units, true)) {
                throw new TeleStaffWorkbookException($errorCode);
            }
        }
    }

    /**
     * The observed table structure: each header label in order, with its column gap from the first. A
     * whole-table shift, renamed sections or divisions and a new parser version are not layout changes.
     *
     * @param  array<int, string|int|float|bool>  $headerCells  Non-blank cells by column index, in order
     */
    private function layoutFingerprint(array $headerCells): string
    {
        $firstColumn = (int) array_key_first($headerCells);
        $structure = [];
        foreach ($headerCells as $column => $label) {
            $structure[] = [(string) $label, $column - $firstColumn];
        }

        return hash('sha256', json_encode($structure, JSON_THROW_ON_ERROR));
    }

    /**
     * Counts only. Nothing outside the primary block is validated, and no member data leaves here.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<TeleStaffRosterBlockSummary>
     */
    private function summarizeOtherBlocks(array $blocks, int $primaryTitleRow): array
    {
        $summaries = [];
        foreach ($blocks as $block) {
            if ($block['titleRow'] === $primaryTitleRow) {
                continue;
            }

            $sections = 0;
            $entries = 0;
            $expectSection = false;
            foreach ($block['rows'] as $cells) {
                if ($this->isHeaderCandidate($cells)) {
                    $expectSection = true;
                } elseif ($expectSection) {
                    $sections++;
                    $expectSection = false;
                } else {
                    $entries++;
                }
            }

            $summaries[] = new TeleStaffRosterBlockSummary($block['titleRow'], $block['division'], $block['shiftLabel'], $sections, $entries);
        }

        return $summaries;
    }
}
