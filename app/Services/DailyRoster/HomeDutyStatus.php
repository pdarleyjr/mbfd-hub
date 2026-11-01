<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\User;
use App\Services\Security\DailyRosterAccess;
use App\Support\Captain5\DutyCalendar;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The Home greeting block: time of day, the civil date, the shift on duty and the 300. Display only.
 *
 * The greeting and the date line follow the clock. The shift and the 300 follow the 08:00 to 08:00 duty
 * day, so at 07:30 the date has moved on while the duty day has not. Duty lines exist only for a member
 * DailyRosterAccess admits; a guest or a member without roster access gets the greeting and the date alone.
 *
 * The 300 comes from OnDutyOfficerDisplayResolver and nothing else. Authority, narcotics review and
 * notification routing keep reading Captain5DutyRoster; no decision may read this class.
 */
final class HomeDutyStatus
{
    /** Hours (Eastern wall clock) at which the greeting or the duty day changes: midnight, duty day, noon, evening. */
    private const BOUNDARY_HOURS = [0, 8, 12, 18];

    /** Why the roster cannot say who holds the seat right now. */
    private const REVIEW_REASONS = [
        OnDutyOfficerDisplay::REASON_CONFLICT,
        OnDutyOfficerDisplay::REASON_NEEDS_REVIEW,
        OnDutyOfficerDisplay::REASON_NO_WORKING_ROW,
        OnDutyOfficerDisplay::REASON_SECTION_MISSING,
    ];

    public function __construct(
        private readonly DailyRosterAccess $access,
        private readonly DutyCalendar $calendar,
        // The roster services are resolved inside the guarded block, so a fault building them degrades to the greeting.
        private readonly Container $container,
    ) {}

    public function forMember(?User $user, ?CarbonInterface $at = null): HomeDutyLines
    {
        // UTC, because query bindings drop the zone and the database stores UTC (as OnDutyOfficerDisplayResolver does).
        $moment = CarbonImmutable::instance($at ?? now())->utc();
        $local = $moment->setTimezone(HubTimezone::NAME);
        $shift = null;
        $command = null;
        $asOf = null;
        $rosterUrl = null;

        try {
            if ($user instanceof User && $this->access->canView($user)) {
                $shift = $this->shift($moment);
                [$command, $asOf] = $this->command($moment);
                $rosterUrl = $this->access->canSeeHomeEntry($user) ? route('daily-roster.show', absolute: false) : null;
            }
        } catch (Throwable $failure) {
            // The greeting must survive. Code only: the original can be a query error, whose bindings carry employee IDs.
            report(new RosterPipelineException('home_duty_failed_'.class_basename($failure)));
            [$shift, $command, $asOf, $rosterUrl] = [null, null, null, null];
        }

        return new HomeDutyLines(
            greeting: $this->greeting($local, $user),
            dateLabel: $local->format('l, F j, Y'),
            dateIso: $local->format('Y-m-d'),
            shift: $shift === null ? null : $this->shiftText($shift, $this->dutyDayNote($moment, $local)),
            command: $command,
            asOf: $asOf,
            rosterUrl: $rosterUrl,
            nextChangeAt: $this->nextChange($local)->utc()->toIso8601String(),
        );
    }

    /** Before noon, noon to 17:59, then evening, on the Eastern wall clock. */
    public static function greetingFor(CarbonInterface $local): string
    {
        $hour = CarbonImmutable::instance($local)->setTimezone(HubTimezone::NAME)->hour;

        return $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    }

    private function greeting(CarbonImmutable $local, ?User $user): string
    {
        $name = (string) ($user?->display_name ?: $user?->name);
        $first = trim((string) strtok($name, ' '));

        return self::greetingFor($local).($first !== '' ? ', '.$first : '');
    }

    /**
     * The shift letter is a fact only when today's accepted roster names the letter the rotation expects. With no
     * accepted roster the rotation alone is shown, as scheduled. A disagreement, or no rotation, shows no letter.
     *
     * @return array{label: string, state: string}|null
     */
    private function shift(CarbonImmutable $moment): ?array
    {
        $dutyDate = $this->calendar->dutyDate($moment)->format('Y-m-d');
        $scheduled = $this->calendar->shiftFor($dutyDate);
        if ($scheduled === null) {
            return null;
        }
        $scheduledShift = ['label' => $scheduled.' Shift', 'state' => HomeDutyLines::SHIFT_SCHEDULED];

        // The kill switch: nothing from the roster is shown as verified, and the roster is not read.
        if (! $this->cardDisplayEnabled()) {
            return $scheduledShift;
        }

        $snapshotId = $this->freshness()->status($moment)->snapshotId;
        if ($snapshotId === null) {
            return $scheduledShift;
        }

        return RosterSnapshot::query()->whereKey($snapshotId)->value('shift_letter') === $scheduled
            ? ['label' => $scheduled.' Shift', 'state' => HomeDutyLines::SHIFT_VERIFIED]
            : null;
    }

    /**
     * "A Shift", "A Shift (scheduled)", "A Shift (on duty since Nov 1, 8:00 AM)" or both qualifiers.
     *
     * @param  array{label: string, state: string}  $shift
     * @return array{text: string, state: string}
     */
    private function shiftText(array $shift, ?string $dutyDayNote): array
    {
        $qualifiers = array_filter([
            $shift['state'] === HomeDutyLines::SHIFT_SCHEDULED ? 'scheduled' : null,
            $dutyDayNote,
        ]);

        return [
            'text' => $shift['label'].($qualifiers === [] ? '' : ' ('.implode(', ', $qualifiers).')'),
            'state' => $shift['state'],
        ];
    }

    /**
     * @return array{0: array{text: string, state: string}|null, 1: string|null} The 300 line and its "As of" text
     */
    private function command(CarbonImmutable $moment): array
    {
        if (! $this->cardDisplayEnabled()) {
            return [null, null];
        }

        $display = $this->container->make(OnDutyOfficerDisplayResolver::class)->resolve(Captain5ActingRequest::ROLE_COMMAND_300, $moment);
        $status = $this->freshness()->status($moment);
        $line = $this->commandLine($display, $status->state);
        if ($line === null) {
            return [null, null];
        }

        return [$line, $this->asOf($display, $status->state, $moment)];
    }

    /** @return array{text: string, state: string}|null */
    private function commandLine(OnDutyOfficerDisplay $display, string $rosterState): ?array
    {
        $name = $display->name;
        $who = $name === null ? null : trim(($display->rank !== null ? $display->rank.' ' : '').$name);
        $line = static fn (string $text, string $state): array => ['text' => $text, 'state' => $state];

        if (in_array($display->reason, self::REVIEW_REASONS, true)) {
            return $line('300: Assignment needs review', HomeDutyLines::COMMAND_REVIEW);
        }
        // An approved acting member. Compared as a literal on purpose: acting is being retired from the resolver, and
        // after that this branch is simply never taken. Read from the seat answer, which both versions carry.
        if ($display->seat->source === 'acting' && $who !== null) {
            return $line("Acting 300 is {$who}", HomeDutyLines::COMMAND_VERIFIED);
        }
        if ($display->source === OnDutyOfficerDisplay::SOURCE_TELESTAFF && $who !== null) {
            return $line("300 is {$who}", HomeDutyLines::COMMAND_VERIFIED);
        }

        // Anything else is the bid seat at best: it is named, but never as the verified 300.
        $held = $display->freshness === OnDutyOfficerDisplay::FRESHNESS_AWAITING && $rosterState === RosterFreshness::NEEDS_REVIEW;

        return match (true) {
            $display->freshness === OnDutyOfficerDisplay::FRESHNESS_DELAYED => $name === null
                ? $line("300: Today's roster is delayed", HomeDutyLines::COMMAND_DELAYED)
                : $line("Today's roster is delayed · Scheduled 300: {$name}", HomeDutyLines::COMMAND_DELAYED),
            $held => $name === null
                ? $line("300: Today's roster is on hold", HomeDutyLines::COMMAND_HELD)
                : $line("Today's roster is on hold · Scheduled 300: {$name}", HomeDutyLines::COMMAND_HELD),
            $display->freshness === OnDutyOfficerDisplay::FRESHNESS_AWAITING => $name === null
                ? $line("300: Awaiting today's roster", HomeDutyLines::COMMAND_AWAITING)
                : $line("Scheduled 300: {$name} · not yet verified", HomeDutyLines::COMMAND_SCHEDULED),
            // The roster could not be read, so the seat name is all there is. It is not verified.
            $name !== null => $line("Scheduled 300: {$name} · not yet verified", HomeDutyLines::COMMAND_SCHEDULED),
            default => null,
        };
    }

    private function asOf(OnDutyOfficerDisplay $display, string $rosterState, CarbonImmutable $moment): ?string
    {
        if ($display->asOf === null) {
            return null;
        }
        $local = $display->asOf->setTimezone(HubTimezone::NAME);
        $now = $moment->setTimezone(HubTimezone::NAME);
        $text = 'As of '.($local->isSameDay($now) ? $local->format('g:i A') : $local->format('M j, g:i A'));

        return $text.match ($rosterState) {
            RosterFreshness::DELAYED => ' · roster updates delayed',
            RosterFreshness::NEEDS_REVIEW => ' · newer export under review',
            default => '',
        };
    }

    /** Between midnight and 08:00 the date has moved on but the duty day has not; say when it began. */
    private function dutyDayNote(CarbonImmutable $moment, CarbonImmutable $local): ?string
    {
        $dutyDate = $this->calendar->dutyDate($moment);
        if ($dutyDate->format('Y-m-d') === $local->format('Y-m-d')) {
            return null;
        }

        return 'on duty since '.$this->calendar->window($dutyDate->format('Y-m-d'))[0]->setTimezone(HubTimezone::NAME)->format('M j, g:i A');
    }

    /** The next wall-clock boundary after now: a new greeting, a new date or a new duty day. */
    private function nextChange(CarbonImmutable $local): CarbonImmutable
    {
        $candidates = [];
        foreach ([0, 1] as $days) {
            foreach (self::BOUNDARY_HOURS as $hour) {
                $candidate = $local->startOfDay()->addDays($days)->setTime($hour, 0);
                if ($candidate->greaterThan($local)) {
                    $candidates[] = $candidate;
                }
            }
        }
        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $candidates[0];
    }

    private function cardDisplayEnabled(): bool
    {
        return (bool) config('daily_roster.card_display_enabled', false);
    }

    private function freshness(): RosterFreshnessResolver
    {
        return $this->container->make(RosterFreshnessResolver::class);
    }
}
