<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterSnapshot;

/**
 * What an open page needs to move from the roster it shows to the current one: only the seats that
 * differ. Snapshots stay whole and immutable, so atomic publishing and rollback are untouched; the diff
 * exists only on the way to the screen. It compares what the page would render, so anything that would
 * look different counts as a change. Null means "take the whole roster", which is always safe: an
 * unknown snapshot, another date, sections added or reordered, or so much changed that a spot update
 * is no longer the cheaper way.
 */
final class RosterChangeBuilder
{
    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly RosterFreshnessResolver $freshness,
        private readonly DailyRosterPagePresenter $presenter,
    ) {}

    /** @return array<string, mixed>|null */
    public function since(int $fromSnapshotId, ?string $clientState): ?array
    {
        $status = $this->freshness->pageStatus();
        if ($status->state !== RosterFreshness::CURRENT
            || $clientState !== RosterFreshness::CURRENT
            || $status->snapshotId === null
            || $fromSnapshotId === $status->snapshotId) {
            return null;
        }

        $from = RosterSnapshot::query()->find($fromSnapshotId);
        if ($from === null || $from->duty_date->format('Y-m-d') !== $status->dutyDate) {
            return null;
        }

        $current = $this->reader->forDutyDate($status->dutyDate);
        if ($current === null || (int) $current->snapshot->getKey() !== $status->snapshotId) {
            return null; // The pointer moved while this was being read.
        }

        $before = $this->presenter->present($this->reader->forSnapshot($from));
        $after = $this->presenter->present($current);

        $keysOf = static fn (array $sections): array => array_column($sections, 'key');
        if ($keysOf($before['sections']) !== $keysOf($after['sections'])
            || count(array_unique($keysOf($after['sections']))) !== count($after['sections'])) {
            return null;
        }

        $seats = [];
        $sections = [];
        $counts = [];
        $changed = 0;
        $total = 0;
        foreach ($after['sections'] as $index => $section) {
            $previous = $before['sections'][$index];
            $total += count($section['seats']);

            if ($previous['entryCount'] !== $section['entryCount']) {
                $counts[] = ['section' => $section['key'], 'label' => self::entryLabel((int) $section['entryCount'])];
            }

            if (count($previous['seats']) !== count($section['seats']) || self::frame($previous) !== self::frame($section)) {
                $sections[] = $section;
                $changed += max(count($previous['seats']), count($section['seats']));

                continue;
            }

            foreach ($section['seats'] as $seatIndex => $seat) {
                if (self::fingerprint($seat) !== self::fingerprint($previous['seats'][$seatIndex])) {
                    $seats[] = ['section' => $section['key'], 'seat' => $seatIndex, 'rows' => $seat];
                    $changed++;
                }
            }
        }

        if ($total > 0 && $changed * 100 / $total > (int) config('daily_roster.live_updates.max_changed_seat_percent', 50)) {
            return null;
        }

        return [
            'mode' => 'changes',
            'version' => $status->version,
            'etag' => $status->etag,
            'snapshot' => (int) $current->snapshot->getKey(),
            'state' => $status->state,
            'changed' => $changed,
            'seats' => array_map(static fn (array $seat): array => [
                'section' => $seat['section'],
                'seat' => $seat['seat'],
                'html' => view('daily-roster._seat', ['seat' => $seat['rows'], 'seatIndex' => $seat['seat']])->render(),
            ], $seats),
            'sections' => array_map(static fn (array $section): array => [
                'section' => $section['key'],
                'html' => view('daily-roster._section', ['section' => $section])->render(),
            ], $sections),
            'counts' => $counts,
            'summary' => view('daily-roster._summary', ['summary' => $after['summary']])->render(),
            'legend' => view('daily-roster._legend_items', ['legend' => $after['legend']])->render(),
            'filters' => $after['filters'],
            'facts' => $this->presenter->liveFacts($status),
        ];
    }

    /** @param array<string, mixed> $section Everything about a section except its seats and how many entries it has. */
    private static function frame(array $section): string
    {
        return self::fingerprint([$section['title'], $section['search'], $section['image']]);
    }

    private static function fingerprint(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function entryLabel(int $count): string
    {
        return $count.' '.($count === 1 ? 'entry' : 'entries');
    }
}
