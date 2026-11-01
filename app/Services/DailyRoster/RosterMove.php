<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;

/**
 * One member's change in what is left of their shift, in the words they read. The title goes on a lock
 * screen, so it names a unit and a time and never a person or a reason.
 */
final readonly class RosterMove
{
    /**
     * @param  list<RosterMovePlacement>  $before  In start order
     * @param  list<RosterMovePlacement>  $after  In start order
     */
    public function __construct(
        public array $before,
        public array $after,
        private CarbonImmutable $now,
    ) {}

    public function title(): string
    {
        if ($this->after === []) {
            return 'Roster change: not listed for the rest of this shift';
        }

        $added = self::missingFrom($this->after, $this->before);
        $seatsBefore = array_map(static fn (RosterMovePlacement $placement): string => $placement->seat(), $this->before);
        foreach ($added as $placement) {
            if (! in_array($placement->seat(), $seatsBefore, true)) {
                return 'Roster move: '.$placement->unit.($this->hasStarted($placement) ? '' : ' from '.self::time($placement->startsAt));
            }
        }

        if ($added !== []) {
            return 'Roster change: '.$added[0]->unit.' until '.self::time($added[0]->endsAt);
        }

        return 'Roster change: no longer on '.(self::missingFrom($this->before, $this->after)[0]->unit ?? 'part of this shift');
    }

    public function body(): string
    {
        return 'Was: '.$this->describe($this->before).'. Now: '.$this->describe($this->after).'.';
    }

    /** @param  list<RosterMovePlacement>  $placements */
    private function describe(array $placements): string
    {
        if ($placements === []) {
            return 'not listed for the rest of this shift';
        }

        return implode(', then ', array_map(fn (RosterMovePlacement $placement): string => $placement->unit.' as '.$placement->rank
            .($this->hasStarted($placement)
                ? ' until '.self::time($placement->endsAt)
                : ' from '.self::time($placement->startsAt).' to '.self::time($placement->endsAt)), $placements));
    }

    private function hasStarted(RosterMovePlacement $placement): bool
    {
        return $placement->startsAt->lessThanOrEqualTo($this->now);
    }

    /**
     * @param  list<RosterMovePlacement>  $placements
     * @param  list<RosterMovePlacement>  $others
     * @return list<RosterMovePlacement>
     */
    private static function missingFrom(array $placements, array $others): array
    {
        $keys = array_map(static fn (RosterMovePlacement $placement): string => $placement->key(), $others);

        return array_values(array_filter($placements, static fn (RosterMovePlacement $placement): bool => ! in_array($placement->key(), $keys, true)));
    }

    /** The 24-hour clock the roster itself prints. */
    private static function time(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(HubTimezone::NAME)->format('H:i');
    }
}
