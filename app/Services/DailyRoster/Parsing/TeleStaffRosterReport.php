<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

final readonly class TeleStaffRosterReport
{
    /**
     * @param  string  $windowStartAt  UTC ISO-8601
     * @param  string  $windowEndAt  UTC ISO-8601
     * @param  string|null  $generatedAt  UTC ISO-8601 from the print footer (read as America/New_York)
     * @param  list<TeleStaffRosterSection>  $sections
     * @param  list<TeleStaffRosterBlockSummary>  $otherBlocks
     * @param  list<string>  $warnings  Codes and row numbers only, never names
     */
    public function __construct(
        public string $parserVersion,
        public string $sourceSha256,
        public string $sheetName,
        public string $dutyDate,
        public string $shift,
        public string $windowStartAt,
        public string $windowEndAt,
        public ?string $generatedAt,
        public string $divisionTitle,
        public int $titleRow,
        public string $layoutFingerprint,
        public array $sections,
        public array $otherBlocks,
        public array $warnings,
    ) {}

    /**
     * Hash of the roster content. Excludes export time and file bytes, so a re-export of the same roster
     * matches. Includes each row's availability, so a code the Hub has just learned reaches today's roster
     * on the next export instead of waiting for TeleStaff to change something.
     */
    public function normalizedSha256(): string
    {
        $sections = [];
        foreach ($this->sections as $section) {
            $seats = [];
            foreach ($section->seats as $seat) {
                $entries = [];
                foreach ($seat->entries as $entry) {
                    $entries[] = [
                        $entry->kind, $entry->employeeId, $entry->rawName, $entry->rawCode,
                        $entry->rawFrom, $entry->rawThrough, $entry->reportedHours, $entry->availability,
                    ];
                }
                $seats[] = [$seat->rawRank, $entries];
            }
            $sections[] = [$section->rawTitle, $seats];
        }

        return hash('sha256', json_encode([$this->dutyDate, $this->shift, $sections], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function assignmentCount(): int
    {
        $count = 0;
        foreach ($this->sections as $section) {
            foreach ($section->seats as $seat) {
                $count += count($seat->entries);
            }
        }

        return $count;
    }

    public function uniqueEmployeeCount(): int
    {
        $ids = [];
        foreach ($this->sections as $section) {
            foreach ($section->seats as $seat) {
                foreach ($seat->entries as $entry) {
                    if ($entry->employeeId !== null) {
                        $ids[$entry->employeeId] = true;
                    }
                }
            }
        }

        return count($ids);
    }
}
