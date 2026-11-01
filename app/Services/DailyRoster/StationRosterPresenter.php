<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\Station;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;

/**
 * The part of the roster on duty right now that belongs to one station, built from what the Daily Roster page
 * shows so the two can never disagree. Follows the schedule, not the posting: when TeleStaff posts the next shift
 * early, a station page keeps showing the crew working until 08:00 and only points to the next one.
 */
final class StationRosterPresenter
{
    public function __construct(
        private readonly DailyRosterPagePresenter $page,
        private readonly RosterFreshnessResolver $freshness,
        private readonly DutyCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function forStation(Station $station): array
    {
        $now = CarbonImmutable::now();
        $onDutyDate = $this->calendar->dutyDate($now)->format('Y-m-d');
        $roster = $this->page->build($onDutyDate);
        $nextShiftPosted = $this->freshness->pageStatus($now)->dutyDate !== $onDutyDate;

        // The page falls back to an older or a later roster; on a station page either would name the wrong crew.
        if ($roster['snapshotId'] === null || $roster['displayedDate'] !== $onDutyDate) {
            return [
                'available' => false,
                'message' => $this->freshness->status($now)->state === RosterFreshness::NEEDS_REVIEW
                    ? "Today's roster is being reviewed. It will appear here once it is accepted."
                    : "Today's roster has not arrived yet. It will appear here on its own.",
                'next_shift_posted' => $nextShiftPosted,
                'sections' => [],
            ];
        }

        return [
            'available' => true,
            'date_label' => $roster['displayedDateLabel'],
            'shift' => $roster['summary']['shift'],
            'window' => $roster['facts']['window'],
            'updated' => $roster['facts']['updated'],
            'tag' => $roster['tag'],
            'notice' => $roster['notice'],
            'next_shift_posted' => $nextShiftPosted,
            'sections' => array_values(array_map(
                fn (array $section): array => $this->section($section),
                array_filter($roster['sections'], fn (array $section): bool => $this->belongsTo($section['title'], (string) $station->station_number)),
            )),
        ];
    }

    /** TeleStaff titles a station's sections "Station 1 / Ladder 1". Float pools and the 300 name no station, so they match none. */
    private function belongsTo(string $title, string $stationNumber): bool
    {
        return preg_match('/\AStation\s+'.preg_quote($stationNumber, '/').'\s*\//', $title) === 1;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function section(array $section): array
    {
        /** @var RosterUnitImage $image */
        $image = $section['image'];

        return [
            'key' => $section['key'],
            'unit' => trim((string) substr($section['title'], (int) strpos($section['title'], '/') + 1)),
            'image' => $image->kind === RosterUnitImage::PICTOGRAM ? null : [
                'src' => $image->src,
                'srcset' => $image->srcset,
                'width' => $image->width,
                'height' => $image->height,
            ],
            'seats' => array_map(
                fn (array $seat): array => array_map($this->row(...), $seat),
                $section['seats'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        /** @var RosterStatusPill $pill */
        $pill = $row['pill'];

        return [
            'is_first_in_seat' => $row['isFirstInSeat'],
            'is_vacant' => $row['isVacant'],
            'rank' => $row['rank'],
            'employee_id' => $row['employeeId'],
            'name' => $row['name'],
            'source_marker' => $row['sourceMarker'],
            'certifications' => $row['certifications'],
            'status' => [
                'label' => $pill->label,
                'tone' => $pill->tone,
                'title' => $pill->title,
                'screen_reader_text' => $pill->screenReaderText,
            ],
            'from' => $row['from'],
            'through' => $row['through'],
            'hours' => $row['hours'],
        ];
    }
}
