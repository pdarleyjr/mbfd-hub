<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSnapshot;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Everything the Daily Roster page renders, read only through CurrentRosterReader and
 * RosterFreshnessResolver. Cell text is untrusted and is escaped by the views.
 */
final class DailyRosterPagePresenter
{
    private const MODE_CURRENT = 'current';

    private const MODE_STALE = 'stale';

    private const MODE_DATED = 'dated';

    private const MODE_EMPTY = 'empty';

    private const PDF_LOGO = 'images/mbfd_logo-256.png';

    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly RosterFreshnessResolver $freshness,
        private readonly RosterUnitPresenter $units,
        private readonly RosterStatusPresenter $statuses,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $requestedDate): array
    {
        $now = CarbonImmutable::now();
        $freshness = $this->freshness->status($now);
        $available = $this->reader->availableDutyDates();
        $date = in_array($requestedDate, $available, true) && $requestedDate !== $freshness->dutyDate ? $requestedDate : null;

        $view = $date !== null ? $this->reader->forDutyDate($date) : $this->reader->current($now);
        $mode = $date !== null ? self::MODE_DATED : self::MODE_CURRENT;
        if ($view === null) {
            $date = null;
            $view = $this->reader->latestBefore($now);
            $mode = $view === null ? self::MODE_EMPTY : self::MODE_STALE;
        }

        $displayedDate = $view?->snapshot->duty_date->format('Y-m-d') ?? $freshness->dutyDate;
        $content = $view === null ? ['sections' => [], 'summary' => null, 'legend' => []] : $this->present($view);

        return [
            'version' => $freshness->version,
            'etag' => $freshness->etag,
            'state' => $freshness->state,
            'snapshotId' => $view === null ? null : (int) $view->snapshot->getKey(),
            // Only the current roster moves on its own; an earlier one on screen is a settled record.
            'isLive' => $mode === self::MODE_CURRENT,
            'mode' => $mode,
            'isEmpty' => $mode === self::MODE_EMPTY,
            'requestedDate' => $date,
            'displayedDate' => $displayedDate,
            'displayedDateLabel' => $this->dateLabel($displayedDate, 'l, F j'),
            'tag' => $this->tag($mode, $freshness, $displayedDate),
            'notice' => $this->notice($mode, $freshness, $displayedDate),
            'facts' => $view === null ? null : $this->facts($view),
            'summary' => $content['summary'],
            'previousDate' => $this->neighbour($available, $displayedDate, 1, $freshness->dutyDate),
            'nextDate' => $this->neighbour($available, $displayedDate, -1, $freshness->dutyDate),
            'sections' => $content['sections'],
            'legend' => $content['legend'],
        ];
    }

    /**
     * The same roster the page shows, so the export can never disagree with it.
     *
     * @return array{roster: array<string, mixed>, logo: ?string, exportedAt: string}
     */
    public function pdfData(?string $requestedDate): array
    {
        $logo = public_path(self::PDF_LOGO);

        return [
            'roster' => $this->build($requestedDate),
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
            'exportedAt' => $this->localTime(CarbonImmutable::now()),
        ];
    }

    /**
     * What one roster renders as, so the page and a spot update can never disagree.
     *
     * @return array{sections: list<array<string, mixed>>, summary: array<string, int|string>, legend: list<RosterStatusPill>}
     */
    public function present(RosterView $view): array
    {
        $sections = $this->sections($view);

        return [
            'sections' => $sections,
            'summary' => $this->summary($view->snapshot, $sections),
            'legend' => $this->legend($sections),
        ];
    }

    /**
     * The times a page moves in place when an export changes nothing. Cheap: no roster is read.
     *
     * @return array{generated: ?string, updated: ?string, changed: ?string}
     */
    public function liveFacts(RosterFreshness $freshness): array
    {
        return [
            'generated' => $freshness->asOf === null ? null : $this->localTime($freshness->asOf),
            'updated' => $freshness->checkedAt === null ? null : $this->localTime($freshness->checkedAt),
            'changed' => $freshness->changedAt === null ? null : $this->localTime($freshness->changedAt),
        ];
    }

    /** @return array{label: string, class: string} */
    private function tag(string $mode, RosterFreshness $freshness, string $displayedDate): array
    {
        if ($mode === self::MODE_DATED) {
            return $displayedDate < $freshness->dutyDate
                ? ['label' => 'Earlier roster', 'class' => '']
                : ['label' => 'Upcoming roster', 'class' => 'hub-tag--info'];
        }

        return match ($freshness->state) {
            RosterFreshness::CURRENT => ['label' => 'Data current', 'class' => 'hub-tag--success'],
            RosterFreshness::DELAYED => ['label' => 'Update delayed', 'class' => 'hub-tag--warning'],
            RosterFreshness::NEEDS_REVIEW => ['label' => 'Needs review', 'class' => 'hub-tag--warning'],
            default => ['label' => "Awaiting today's roster", 'class' => 'hub-tag--info'],
        };
    }

    private function notice(string $mode, RosterFreshness $freshness, string $displayedDate): ?string
    {
        $shown = $this->dateLabel($displayedDate, 'l, F j');

        return match (true) {
            $mode === self::MODE_STALE && $freshness->state === RosterFreshness::NEEDS_REVIEW => "Today's roster is being reviewed. This is the last published roster, from {$shown}.",
            $mode === self::MODE_STALE && $freshness->state === RosterFreshness::DELAYED => "Today's roster has not arrived and is late. This is the last published roster, from {$shown}.",
            $mode === self::MODE_STALE => "Today's roster has not arrived yet. This is the last published roster, from {$shown}.",
            $mode === self::MODE_CURRENT && $freshness->state === RosterFreshness::NEEDS_REVIEW => 'A newer TeleStaff export is being reviewed. This is the last accepted roster for today.',
            $mode === self::MODE_CURRENT && $freshness->state === RosterFreshness::DELAYED => 'TeleStaff has stopped sending updates'.($freshness->asOf === null ? '' : ' since '.$this->localTime($freshness->asOf)).'. This roster may be out of date.',
            default => null,
        };
    }

    /** @return array{division: list<string>, window: string, generated: string, updated: string, changed: string, changedHidden: bool} */
    private function facts(RosterView $view): array
    {
        $snapshot = $view->snapshot;
        // The newest export that confirmed this roster, not the first one that carried it.
        $generated = $view->asOf ?? $snapshot->source_generated_at;
        $updated = $view->updatedAt ?? $generated;
        $changed = $view->changedAt ?? $snapshot->created_at;

        $updatedLabel = $updated === null ? 'Not recorded' : $this->localTime($updated);
        $changedLabel = $changed === null ? 'Not recorded' : $this->localTime($changed);

        return [
            'division' => array_values(array_filter(array_map('trim', explode(' / ', (string) $snapshot->division)))),
            'window' => $this->moment($snapshot->starts_at).' to '.$this->moment($snapshot->ends_at).' ET',
            'generated' => $generated === null ? 'Not recorded' : $this->localTime($generated),
            'updated' => $updatedLabel,
            'changed' => $changedLabel,
            // Two identical times say nothing: it only earns a line once the roster has been re-confirmed.
            'changedHidden' => $changedLabel === $updatedLabel,
        ];
    }

    /** Miami Beach wall clock, naming EDT or EST so the time stays unambiguous across a clock change. */
    private function localTime(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(HubTimezone::NAME)->format('M j, g:i A T');
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, int|string>
     */
    private function summary(RosterSnapshot $snapshot, array $sections): array
    {
        return [
            'shift' => $snapshot->shift_letter.' Shift',
            'date' => $snapshot->duty_date->format('M j, Y'),
            'sections' => count($sections),
            'entries' => array_sum(array_column($sections, 'entryCount')),
            'version' => (int) $snapshot->version,
        ];
    }

    /**
     * @param  list<string>  $available  Newest first
     * @return array{label: string, href: string}|null
     */
    private function neighbour(array $available, string $displayedDate, int $step, string $currentDutyDate): ?array
    {
        $index = array_search($displayedDate, $available, true);
        if ($index === false || ! isset($available[$index + $step])) {
            return null;
        }
        $date = $available[$index + $step];

        return [
            'label' => $this->dateLabel($date, 'M j'),
            'href' => route('daily-roster.show', $date === $currentDutyDate ? [] : ['date' => $date]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sections(RosterView $view): array
    {
        $sections = [];
        foreach ($view->sections as $index => $sectionView) {
            $section = $sectionView->section;
            $seats = [];
            $seatKey = null;
            foreach ($sectionView->assignments as $assignment) {
                $isNewSeat = $assignment->seat_position !== $seatKey;
                $seatKey = $assignment->seat_position;
                if ($isNewSeat) {
                    $seats[] = [];
                }
                $seats[array_key_last($seats)][] = $this->row($assignment, $isNewSeat);
            }

            $sections[] = [
                'key' => (string) $section->raw_title,
                'title' => (string) $section->raw_title,
                'image' => $this->units->present((string) $section->unit_label),
                'eager' => $index < 2,
                'entryCount' => count($sectionView->assignments),
                'seats' => $seats,
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    private function row(RosterAssignment $assignment, bool $isFirstInSeat): array
    {
        $pill = $this->statuses->present($assignment);
        $isVacant = $assignment->kind !== RosterAssignment::KIND_EMPLOYEE;
        $name = $isVacant
            ? RosterStatusPresenter::vacancyLabel($assignment)
            : (string) ($assignment->display_name ?: $assignment->raw_name);
        $certifications = array_values(array_filter(array_map(
            static fn (mixed $certification): string => trim((string) $certification),
            (array) $assignment->certifications,
        ), static fn (string $certification): bool => $certification !== ''));

        return [
            'isFirstInSeat' => $isFirstInSeat,
            'isVacant' => $isVacant,
            // TeleStaff leaves the rank blank on a continuation row; the rank belongs to the seat.
            'rank' => (string) ($assignment->rank_label ?: $assignment->seat_rank),
            'employeeId' => (string) $assignment->employee_id,
            'name' => $name,
            'sourceMarker' => $isVacant ? trim((string) $assignment->raw_name) : '',
            'certifications' => $certifications,
            'pill' => $pill,
            'from' => (string) $assignment->raw_from,
            'through' => (string) $assignment->raw_through,
            'hours' => number_format((float) $assignment->reported_hours, 1),
        ];
    }

    /**
     * Only the statuses on this roster, in the order TeleStaff members already read them.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<RosterStatusPill>
     */
    private function legend(array $sections): array
    {
        $pills = [];
        foreach ($this->pills($sections) as $pill) {
            $pills[$pill->label] ??= $pill;
        }

        return $this->ordered($pills);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<RosterStatusPill>
     */
    private function pills(array $sections): array
    {
        $pills = [];
        foreach ($sections as $section) {
            foreach ($section['seats'] as $seat) {
                foreach ($seat as $row) {
                    $pills[] = $row['pill'];
                }
            }
        }

        return $pills;
    }

    /**
     * @param  array<string, RosterStatusPill>  $pills
     * @return list<RosterStatusPill>
     */
    private function ordered(array $pills): array
    {
        $order = array_flip(RosterStatusPresenter::TONE_ORDER);
        uasort($pills, static fn (RosterStatusPill $a, RosterStatusPill $b): int => [$order[$a->tone], $a->filterLabel] <=> [$order[$b->tone], $b->filterLabel]);

        return array_values($pills);
    }

    private function moment(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(HubTimezone::NAME)->format('M j, H:i');
    }

    private function dateLabel(string $date, string $format): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, HubTimezone::NAME)->format($format);
    }
}
