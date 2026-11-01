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

    /** The duty day on the clock: the shift actually working. What the cards and alerts follow. */
    public function status(?CarbonInterface $at = null): RosterFreshness
    {
        $moment = CarbonImmutable::instance($at ?? now());

        return $this->statusFor($this->calendar->dutyDate($moment)->format('Y-m-d'), $moment);
    }

    /**
     * What the Daily Roster page follows: the next shift's roster as soon as TeleStaff has posted it, so
     * members can read it on their way in, otherwise the same as status(). Nothing else reads this.
     */
    public function pageStatus(?CarbonInterface $at = null): RosterFreshness
    {
        $moment = CarbonImmutable::instance($at ?? now());
        $today = $this->calendar->dutyDate($moment);
        $next = $today->addDay()->format('Y-m-d');
        if (! RosterActiveVersion::query()->whereDate('duty_date', $next)->whereNotNull('roster_snapshot_id')->exists()) {
            return $this->status($moment);
        }

        // A late correction to the shift on duty must still reach a member reading it through its link.
        $onDuty = RosterActiveVersion::query()->whereDate('duty_date', $today->format('Y-m-d'))->first();

        return $this->statusFor($next, $moment, 'next|'.($onDuty->roster_snapshot_id ?? ''));
    }

    private function statusFor(string $dutyDate, CarbonImmutable $moment, string $pageContext = 'current'): RosterFreshness
    {
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
        $published = $pointer?->roster_snapshot_id !== null;
        $held = $this->hasHeldImport($dutyDate, $published ? $pointer : null);

        $state = match (true) {
            $held => RosterFreshness::NEEDS_REVIEW,
            $published && $this->isStale($pointer, $moment) => RosterFreshness::DELAYED,
            $published => RosterFreshness::CURRENT,
            ! RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->exists() => RosterFreshness::NONE,
            $this->isPastCutoff($moment) => RosterFreshness::DELAYED,
            default => RosterFreshness::AWAITING,
        };

        // Only what a member would see. An export with no changes must not make every open page refetch.
        // A delayed notice names the last time heard, so a second outage is something new to show.
        // At 08:00 the next shift's roster becomes the current one, and an open page must drop its notice.
        $version = substr(hash('sha256', implode('|', [
            $dutyDate,
            $pageContext,
            $state,
            (string) ($pointer->roster_snapshot_id ?? ''),
            $state === RosterFreshness::DELAYED ? (string) $pointer?->last_confirmed_at?->getTimestamp() : '',
        ])), 0, 32);
        $etag = substr(hash('sha256', implode('|', [
            $version,
            (string) $pointer?->last_confirmed_at?->getTimestamp(),
            (string) $pointer?->updated_at?->getTimestamp(),
        ])), 0, 32);

        return new RosterFreshness(
            $state,
            $dutyDate,
            $pointer?->last_confirmed_at,
            $version,
            $etag,
            $published ? (int) $pointer->roster_snapshot_id : null,
            $pointer?->updated_at?->toImmutable(),
            $pointer?->content_changed_at,
        );
    }

    /** Whether the current duty day's export should have arrived by now. */
    public function isPastCutoff(CarbonInterface $at): bool
    {
        $moment = CarbonImmutable::instance($at);

        // The hours before the duty day starts belong to the previous duty day, already past its cutoff.
        return $moment->greaterThanOrEqualTo($this->lateFrom($this->calendar->dutyDate($moment)->format('Y-m-d')));
    }

    /** When a duty date with no roster yet starts counting as late. */
    public function lateFrom(string $dutyDate): CarbonImmutable
    {
        $staleAfter = $this->staleAfterMinutes();
        if ($staleAfter !== null) {
            return CarbonImmutable::instance($this->calendar->window($dutyDate)[0])->addMinutes($staleAfter);
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) config('daily_roster.delayed_after_local', '10:00')) + [1 => 0]);

        return CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate, $this->calendar->timezone())->setTime($hour, $minute);
    }

    /**
     * Whether TeleStaff has gone quiet while a roster is showing. Only with a cadence set, since a daily
     * export is quiet all day. A pin is an administrator's choice, and exports still arriving but held are
     * trouble with the export, which needs_review already reports, so neither counts as silence.
     */
    public function isStale(?RosterActiveVersion $pointer, CarbonInterface $at): bool
    {
        $staleAfter = $this->staleAfterMinutes();
        if ($staleAfter === null || $pointer === null || $pointer->roster_snapshot_id === null || $pointer->pinned_at !== null) {
            return false;
        }

        $limit = CarbonImmutable::instance($at)->subMinutes($staleAfter);
        $lastHeard = $pointer->last_confirmed_at ?? $pointer->updated_at;
        if ($lastHeard !== null && $lastHeard->greaterThanOrEqualTo($limit)) {
            return false;
        }

        return ! RosterImport::query()
            ->where('received_at', '>=', $limit)
            ->where('state', '!=', RosterImport::STATE_REJECTED)
            ->exists();
    }

    private function staleAfterMinutes(): ?int
    {
        $interval = (int) config('daily_roster.cadence.expected_interval_minutes');

        return $interval > 0 ? $interval * max(1, (int) config('daily_roster.cadence.stale_after_missed', 3)) : null;
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
