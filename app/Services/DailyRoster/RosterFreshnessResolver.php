<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a member should be told about the current duty day's roster. A held or failed export is
 * reported even when an earlier export for the same day is still showing. An export still being
 * retried is not reported until its attempts run out, and an administrator's pin is not trouble.
 */
final class RosterFreshnessResolver
{
    private const HELD_STATES = [
        RosterImport::STATE_NEEDS_REVIEW,
        RosterImport::STATE_PERMANENT_FAILED,
    ];

    public function __construct(private readonly DutyCalendar $calendar) {}

    public function status(?CarbonInterface $at = null): RosterFreshness
    {
        $moment = CarbonImmutable::instance($at ?? now());
        $dutyDate = $this->calendar->dutyDate($moment)->format('Y-m-d');
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
        $published = $pointer?->roster_snapshot_id !== null;
        $held = $this->hasHeldImport($dutyDate, $published ? $pointer : null);

        $state = match (true) {
            $held => RosterFreshness::NEEDS_REVIEW,
            $published => RosterFreshness::CURRENT,
            ! RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->exists() => RosterFreshness::NONE,
            $this->isPastCutoff($moment) => RosterFreshness::DELAYED,
            default => RosterFreshness::AWAITING,
        };

        $version = hash('sha256', implode('|', [
            $dutyDate, $state, (string) ($pointer->roster_snapshot_id ?? ''),
            (string) $pointer?->last_confirmed_at?->getTimestamp(),
            (string) $pointer?->updated_at?->getTimestamp(),
        ]));

        return new RosterFreshness($state, $dutyDate, $pointer?->last_confirmed_at, substr($version, 0, 32));
    }

    /** Whether the current duty day's export should have arrived by now. */
    public function isPastCutoff(CarbonInterface $at): bool
    {
        $moment = CarbonImmutable::instance($at);
        $local = $moment->setTimezone($this->calendar->timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) config('daily_roster.delayed_after_local', '10:00')) + [1 => 0]);

        // The hours before the duty day starts belong to the previous duty day, already past its cutoff.
        return $local->format('Y-m-d') !== $this->calendar->dutyDate($moment)->format('Y-m-d')
            || $local->greaterThanOrEqualTo($local->setTime($hour, $minute));
    }

    /** Trouble older than the roster now showing has been overtaken by a good export, so it no longer counts. */
    private function hasHeldImport(string $dutyDate, ?RosterActiveVersion $showing): bool
    {
        [$start, $end] = $this->calendar->window($dutyDate);

        return RosterImport::query()
            ->whereIn('state', self::HELD_STATES)
            ->where(static fn ($code) => $code->whereNull('error_code')->orWhere('error_code', '!=', RosterPublisher::HOLD_PINNED))
            ->when($showing !== null, static fn ($query) => self::onlyNewerThan($query, $showing))
            ->where(static function ($query) use ($dutyDate, $start, $end): void {
                $query->whereDate('duty_date', $dutyDate)
                    ->orWhere(static function ($unparsed) use ($start, $end): void {
                        $unparsed->whereNull('duty_date')->whereBetween('received_at', [$start, $end]);
                    });
            })
            ->exists();
    }

    /**
     * Export times decide when both are known, because a delayed email can arrive after a newer export.
     * An export never read has only its arrival to go on.
     */
    private static function onlyNewerThan(Builder $query, RosterActiveVersion $showing): Builder
    {
        $effective = $showing->effective_generated_at;
        $confirmed = $showing->last_confirmed_at;

        return $query->where(static function ($newer) use ($effective, $confirmed): void {
            if ($effective !== null) {
                $newer->where('source_generated_at', '>', $effective);
            }
            if ($confirmed !== null) {
                $newer->orWhere(static function ($arrival) use ($effective, $confirmed): void {
                    $arrival->where('received_at', '>', $confirmed)
                        ->when($effective !== null, static fn ($unread) => $unread->whereNull('source_generated_at'));
                });
            }
        });
    }
}
