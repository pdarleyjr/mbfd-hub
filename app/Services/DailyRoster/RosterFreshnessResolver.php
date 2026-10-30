<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a member should be told about the current duty day's roster. A held or failed export is
 * reported even when an earlier export for the same day is still showing.
 */
final class RosterFreshnessResolver
{
    private const HELD_STATES = [
        RosterImport::STATE_NEEDS_REVIEW,
        RosterImport::STATE_PERMANENT_FAILED,
        RosterImport::STATE_RETRYABLE_FAILED,
    ];

    public function __construct(private readonly DutyCalendar $calendar) {}

    public function status(?CarbonInterface $at = null): RosterFreshness
    {
        $moment = CarbonImmutable::instance($at ?? now());
        $dutyDate = $this->calendar->dutyDate($moment)->format('Y-m-d');
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
        $published = $pointer?->roster_snapshot_id !== null;
        $held = $this->hasHeldImport($dutyDate, $published ? $pointer->last_confirmed_at : null);

        $state = match (true) {
            $held => RosterFreshness::NEEDS_REVIEW,
            $published => RosterFreshness::CURRENT,
            ! RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->exists() => RosterFreshness::NONE,
            $this->isPastCutoff($moment) => RosterFreshness::DELAYED,
            default => RosterFreshness::AWAITING,
        };

        $version = hash('sha256', implode('|', [
            $dutyDate, $state, (string) ($pointer?->roster_snapshot_id ?? ''),
            (string) $pointer?->last_confirmed_at?->getTimestamp(),
            (string) $pointer?->updated_at?->getTimestamp(),
        ]));

        return new RosterFreshness($state, $dutyDate, $pointer?->last_confirmed_at, substr($version, 0, 32));
    }

    /** Trouble older than the roster now showing has been overtaken by a good export, so it no longer counts. */
    private function hasHeldImport(string $dutyDate, ?CarbonImmutable $confirmedAt): bool
    {
        [$start, $end] = $this->calendar->window($dutyDate);

        return RosterImport::query()
            ->whereIn('state', self::HELD_STATES)
            ->when($confirmedAt !== null, static fn ($query) => $query->where('received_at', '>', $confirmedAt))
            ->where(static function ($query) use ($dutyDate, $start, $end): void {
                $query->whereDate('duty_date', $dutyDate)
                    ->orWhere(static function ($unparsed) use ($start, $end): void {
                        $unparsed->whereNull('duty_date')->whereBetween('received_at', [$start, $end]);
                    });
            })
            ->exists();
    }

    private function isPastCutoff(CarbonImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->calendar->timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) config('daily_roster.delayed_after_local', '10:00')) + [1 => 0]);

        // The early morning before 08:00 belongs to the previous duty day, already past its cutoff.
        return $local->hour < 8 || $local->greaterThanOrEqualTo($local->setTime($hour, $minute));
    }
}
