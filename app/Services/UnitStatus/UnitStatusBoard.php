<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCoverageGap;
use App\Models\UnitStatus\UnitStatusSyncState;
use App\Support\Captain5\DutyCalendar;
use App\Support\UnitStatus\UnitRegistry;
use App\Support\UnitStatus\UnitStatusTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The one view-model builder behind the page, the refresh fragment and the JSON, so the numbers never diverge. */
final class UnitStatusBoard
{
    public const ADVISORY = 'PulsePoint is advisory. Verify with dispatch.';

    public const DEFINITION = 'A run is a PulsePoint call that listed this unit (an observed assignment). It is not proof the unit responded. Each call counts once per unit; status changes, repeat updates and page refreshes never add runs.';

    public function __construct(
        private readonly UnitRegistry $registry,
        private readonly FeedStatusResolver $feed,
        private readonly CrewProjection $crew,
        private readonly CurrentCallFinder $currentCalls,
        private readonly RunCounts $runCounts,
        private readonly UnitViewBuilder $units,
        private readonly DutyCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $feed = $this->feed->resolve($now);
        $roster = $this->crew->at($now);
        $dutyDate = $this->calendar->dutyDate($now);
        $current = $this->currentCalls->byUnit();
        $counts = $this->runCounts->forDutyDate($dutyDate->format('Y-m-d'));

        $stations = [];
        foreach ($this->registry->stationNumbers() as $number) {
            $stations[] = ['number' => $number, 'label' => 'Station '.$number, 'units' => []];
        }
        foreach ($this->registry->units() as $unit) {
            $index = array_search($unit->station, $this->registry->stationNumbers(), true);
            $stations[$index]['units'][] = $this->units->build($unit, $current[$unit->code] ?? [], ($counts[$unit->code] ?? []) + RunCounts::empty(), $feed, $roster);
        }

        $body = [
            'schemaVersion' => 1,
            'live' => $feed->fresh && $roster->isCurrent(),
            'advisory' => self::ADVISORY,
            'source' => $this->source($feed),
            'roster' => $this->roster($roster),
            'dutyWindow' => $this->dutyWindow($dutyDate, $roster),
            'tracking' => $this->tracking(),
            'stations' => $stations,
        ];

        return ['schemaVersion' => 1, 'generatedAt' => UnitStatusTime::iso($now), 'etag' => sha1((string) json_encode($body))] + $body;
    }

    /** @return array<string, mixed> */
    private function source(FeedStatus $feed): array
    {
        $last = UnitStatusTime::dateTime($feed->lastSyncAt);
        $message = match ($feed->state) {
            FeedStatus::LIVE => 'PulsePoint feed is live.',
            FeedStatus::NOT_CONFIGURED => 'The PulsePoint feed is not configured.',
            default => $feed->note().($last !== null ? '. Last update '.$last.'.' : '.'),
        };

        return [
            'state' => $feed->state,
            'label' => $feed->label(),
            'message' => $message,
            'lastSourceReadAt' => UnitStatusTime::iso($feed->lastSourceReadAt),
            'lastSyncAt' => UnitStatusTime::iso($feed->lastSyncAt),
            'fresh' => $feed->fresh,
        ];
    }

    /** @return array<string, mixed> */
    private function roster(CrewSnapshot $roster): array
    {
        $asOf = UnitStatusTime::dateTime($roster->asOf);
        [$label, $message] = match (true) {
            $roster->state === 'current' => ['Roster current', 'Crew from the published TeleStaff roster.'],
            $roster->hasNames => ['Roster delayed', 'TeleStaff roster delayed. Crew shown is the last published roster'.($asOf !== null ? ', as of '.$asOf : '').'.'],
            $roster->state === 'awaiting' => ['Roster not posted yet', 'Roster not posted yet. Personnel not confirmed.'],
            $roster->state === 'needs_review' => ['Roster needs review', 'The roster export is held for review. Personnel not confirmed.'],
            default => ['No roster', 'No roster has been published. Personnel not confirmed.'],
        };

        return [
            'state' => $roster->state,
            'label' => $label,
            'message' => $message,
            'dutyDate' => $roster->dutyDate,
            'shift' => $roster->shift ?? $this->calendar->shiftFor($roster->dutyDate),
            'asOf' => UnitStatusTime::iso($roster->asOf),
        ];
    }

    /** @return array<string, mixed> */
    private function dutyWindow(CarbonImmutable $dutyDate, CrewSnapshot $roster): array
    {
        [$start, $end] = $this->calendar->window($dutyDate);
        $label = trim(str_replace(' ET', '', (string) UnitStatusTime::dateTime($start))).' – '.UnitStatusTime::dateTime($end);

        return [
            'dutyDate' => $dutyDate->format('Y-m-d'),
            'startsAt' => UnitStatusTime::iso($start),
            'endsAt' => UnitStatusTime::iso($end),
            'label' => $label,
            'shift' => $roster->shift ?? $this->calendar->shiftFor($dutyDate),
        ];
    }

    /** @return array<string, mixed> */
    private function tracking(): array
    {
        $state = UnitStatusSyncState::current();
        $starts = $state !== null ? $state->tracking_starts_at : $this->configuredStart();
        $gaps = UnitStatusCoverageGap::query()->orderByDesc('started_at')->orderByDesc('id')->limit(10)->get();

        return [
            'startsAt' => UnitStatusTime::iso($starts),
            'startsLabel' => UnitStatusTime::dateTime($starts),
            'firstSyncedAt' => UnitStatusTime::iso($state?->first_synced_at),
            'definition' => self::DEFINITION,
            'openGap' => $gaps->contains(static fn (UnitStatusCoverageGap $gap): bool => $gap->ended_at === null),
            'gaps' => $gaps->map(fn (UnitStatusCoverageGap $gap): array => [
                'startedAt' => UnitStatusTime::iso($gap->started_at),
                'endedAt' => UnitStatusTime::iso($gap->ended_at),
                'reason' => $gap->reason,
                'label' => $this->gapLabel($gap),
            ])->values()->all(),
        ];
    }

    private function gapLabel(UnitStatusCoverageGap $gap): string
    {
        $state = str_replace('_', ' ', preg_replace('/\Afeed_/', '', $gap->reason) ?? $gap->reason);
        $end = $gap->ended_at !== null ? UnitStatusTime::time($gap->ended_at) : 'now';

        return 'PulsePoint feed '.$state.': '.UnitStatusTime::dateTime($gap->started_at).' to '.$end;
    }

    private function configuredStart(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) config('unit_status.tracking_starts_at'), (string) config('unit_status.timezone'))->utc();
        } catch (\Throwable) {
            return CarbonImmutable::now()->utc();
        }
    }
}
