<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusRun;
use Illuminate\Database\Eloquent\Builder;

/** Headline numbers count confirmed, non-voided runs; unconfirmed ones are counted apart. */
final class RunCounts
{
    /** @return array<string, array{shift: int, shiftUnconfirmed: int, lifetime: int, lifetimeUnconfirmed: int, backfilledLifetime: int}> */
    public function forDutyDate(string $dutyDate): array
    {
        $live = fn (): Builder => UnitStatusRun::query()->whereNull('voided_at');
        $shift = static fn (Builder $query): Builder => $query->where('duty_date', $dutyDate);
        $columns = [
            'shift' => $this->grouped($shift($live())->where('confirmed', true)),
            'shiftUnconfirmed' => $this->grouped($shift($live())->where('confirmed', false)),
            'lifetime' => $this->grouped($live()->where('confirmed', true)),
            'lifetimeUnconfirmed' => $this->grouped($live()->where('confirmed', false)),
            'backfilledLifetime' => $this->grouped($live()->where('confirmed', true)->where('provenance', UnitStatusRun::PROVENANCE_BACKFILL)),
        ];

        $counts = [];
        foreach ($columns as $column => $perUnit) {
            foreach ($perUnit as $code => $number) {
                $counts[$code][$column] = $number;
            }
        }

        return $counts;
    }

    /** @return array{shift: int, shiftUnconfirmed: int, lifetime: int, lifetimeUnconfirmed: int, backfilledLifetime: int} */
    public static function empty(): array
    {
        return ['shift' => 0, 'shiftUnconfirmed' => 0, 'lifetime' => 0, 'lifetimeUnconfirmed' => 0, 'backfilledLifetime' => 0];
    }

    /**
     * @param  Builder<UnitStatusRun>  $query
     * @return array<string, int>
     */
    private function grouped(Builder $query): array
    {
        return $query->selectRaw('unit_code, count(*) as total')
            ->groupBy('unit_code')
            ->pluck('total', 'unit_code')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }
}
