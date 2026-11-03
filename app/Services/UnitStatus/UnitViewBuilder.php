<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Support\UnitStatus\StatusFamily;
use App\Support\UnitStatus\UnitDefinition;
use App\Support\UnitStatus\UnitRegistry;
use App\Support\UnitStatus\UnitStatusTime;

/** One unit's row of the view model (contract section 8): availability, current calls, runs and crew. */
final class UnitViewBuilder
{
    public function __construct(private readonly UnitRegistry $registry) {}

    /**
     * @param  list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>  $current
     * @param  array{shift: int, shiftUnconfirmed: int, lifetime: int, lifetimeUnconfirmed: int, backfilledLifetime: int}  $runs
     * @return array<string, mixed>
     */
    public function build(UnitDefinition $unit, array $current, array $runs, FeedStatus $feed, CrewSnapshot $roster): array
    {
        $crew = $roster->crew($unit->code);
        $calls = array_map(fn (array $entry): array => $this->callView($entry['call'], $entry['unit'], $unit), $current);

        return [
            'code' => $unit->code,
            'name' => $unit->name,
            'station' => $unit->station,
            'domId' => $unit->domId(),
            'availability' => $this->availability($current, $feed, $roster, $crew),
            'assignmentSummary' => $this->summary($current, $feed),
            'conflict' => count($current) > 1,
            'currentCalls' => $calls,
            'runs' => $runs,
            'crew' => $this->crewView($crew, $roster),
        ];
    }

    /**
     * @param  list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>  $current
     * @return array<string, mixed>
     */
    private function availability(array $current, FeedStatus $feed, CrewSnapshot $roster, UnitCrew $crew): array
    {
        if ($current !== []) {
            $unitRow = $current[0]['unit'];
            $family = StatusFamily::family($unitRow->status);

            return $this->state('on_call', trim($unitRow->status) !== '' ? $unitRow->status : 'Assigned', $family, 'source', StatusFamily::color($family), $unitRow->confirmed, ! $feed->fresh, $feed->fresh ? null : 'May be out of date. '.$feed->note(), $unitRow->status_observed_at);
        }
        if (! $feed->fresh) {
            return $this->state('unknown', 'Not confirmed', 'unknown', 'warning', null, false, true, $feed->note(), $feed->lastSyncAt);
        }
        if ($roster->isCurrent() && $crew->listed) {
            return $crew->hasWorkingCrew()
                ? $this->state('available', 'Available', 'available', 'success', null, true, false, null, $feed->lastSyncAt)
                : $this->state('not_staffed', 'Not staffed', 'not_staffed', 'neutral', null, true, false, null, $feed->lastSyncAt);
        }

        return $this->state('no_active_call', 'No active call', 'no_active_call', 'neutral', null, true, false, 'Crew not confirmed', $feed->lastSyncAt);
    }

    /** @return array<string, mixed> */
    private function state(string $key, string $label, string $family, string $tone, ?string $color, bool $confirmed, bool $stale, ?string $note, ?\DateTimeInterface $observedAt): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'family' => $family,
            'tone' => $tone,
            'indicatorColor' => $color,
            'confirmed' => $confirmed,
            'stale' => $stale,
            'note' => $note,
            'observedAt' => UnitStatusTime::iso($observedAt),
        ];
    }

    /** @param  list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>  $current */
    private function summary(array $current, FeedStatus $feed): string
    {
        if ($current === []) {
            return $feed->fresh ? 'No active call' : 'Not confirmed';
        }
        $call = $current[0]['call'];
        $parts = array_values(array_filter([$call->call_type, $call->address], static fn (string $part): bool => $part !== ''));

        return $parts === [] ? 'Active call' : implode(' – ', $parts);
    }

    /** @return array<string, mixed> */
    private function callView(UnitStatusCall $call, UnitStatusCallUnit $unitRow, UnitDefinition $unit): array
    {
        $family = StatusFamily::family($unitRow->status);
        $positioned = $call->lat !== null && $call->lng !== null && $call->location_quality === 'approximate';

        return [
            'sourceCallId' => $call->source_call_id,
            'externalId' => $call->external_id,
            'idLabel' => $call->external_id !== null ? '#'.$call->external_id : 'ID pending',
            'callType' => $call->call_type,
            'address' => $call->address,
            'receivedAt' => UnitStatusTime::iso($call->received_at),
            'receivedLabel' => UnitStatusTime::time($call->received_at),
            'unitStatus' => $unitRow->status,
            'unitFamily' => $family,
            'indicatorColor' => StatusFamily::color($family),
            'unitConfirmed' => $unitRow->confirmed,
            'sourceStatus' => $call->source_status,
            'lastSeenAt' => UnitStatusTime::iso($call->last_seen_at),
            'lastSeenLabel' => UnitStatusTime::time($call->last_seen_at),
            'sourceUrl' => $call->source_url,
            'location' => $positioned ? ['lat' => $call->lat, 'lng' => $call->lng, 'quality' => 'approximate'] : null,
            'otherUnits' => $this->otherUnits($call, $unit),
        ];
    }

    /** @return list<array{designation: string, status: string}> */
    private function otherUnits(UnitStatusCall $call, UnitDefinition $unit): array
    {
        $others = [];
        foreach ((array) $call->units_json as $listed) {
            $designation = $listed['unitId'];
            if ($designation === '' || $this->registry->matchDesignation($designation)?->unit->code === $unit->code) {
                continue;
            }
            $others[$designation] ??= ['designation' => $designation, 'status' => $listed['status']];
        }

        return array_values($others);
    }

    /** @return array<string, mixed> */
    private function crewView(UnitCrew $crew, CrewSnapshot $roster): array
    {
        [$state, $label] = match (true) {
            ! $roster->hasNames => ['not_posted', 'Roster not posted yet. Personnel not confirmed'],
            ! $crew->listed => ['not_listed', 'Not listed on today\'s roster'],
            ! $crew->hasWorkingCrew() => ['none_working', 'No one working this unit now'],
            ! $roster->isCurrent() => ['delayed', 'On the truck now (roster delayed)'],
            default => ['confirmed', 'On the truck now'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'onTruck' => $crew->onTruck,
            'later' => $crew->later,
            'vacancies' => $crew->vacancies,
            'asOf' => UnitStatusTime::iso($roster->hasNames ? $roster->asOf : null),
            'note' => $roster->hasNames && ! $roster->isCurrent() ? 'Roster delayed. Last update '.(UnitStatusTime::dateTime($roster->asOf) ?? 'unknown').'.' : null,
        ];
    }
}
