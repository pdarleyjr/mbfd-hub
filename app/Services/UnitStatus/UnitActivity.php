<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Models\UnitStatus\UnitStatusRun;
use App\Support\UnitStatus\StatusFamily;
use App\Support\UnitStatus\UnitDefinition;
use App\Support\UnitStatus\UnitRegistry;
use App\Support\UnitStatus\UnitStatusTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the page counts, for all 16 units at once (a handful of grouped queries, never one per unit): runs this
 * shift, runs since tracking began, each unit's busiest duty day, the calls each unit ran this shift, and the shift
 * totals. Only confirmed, non-voided runs are counted anywhere; unconfirmed credits are never counted or shown.
 */
final class UnitActivity
{
    public function __construct(private readonly UnitRegistry $registry) {}

    /**
     * @return array{
     *     units: array<string, array{shift: int, lifetime: int, backfilledLifetime: int, busiestDay: ?array{runs: int, date: string, label: string}, todayRuns: list<array<string, mixed>>}>,
     *     totals: array{total: int, rescue: int, combat: int, busiest: ?array{codes: list<string>, runs: int}}
     * }
     */
    public function forDutyDate(string $dutyDate): array
    {
        $today = $this->todayRuns($dutyDate);
        $lifetime = $this->grouped($this->counted());
        $backfilled = $this->grouped($this->counted()->where('provenance', UnitStatusRun::PROVENANCE_BACKFILL));
        $busiest = $this->busiestDays();

        $units = [];
        foreach ($this->registry->units() as $unit) {
            $runs = $today[$unit->code] ?? [];
            $units[$unit->code] = [
                'shift' => count($runs),
                'lifetime' => $lifetime[$unit->code] ?? 0,
                'backfilledLifetime' => $backfilled[$unit->code] ?? 0,
                'busiestDay' => $busiest[$unit->code] ?? null,
                'todayRuns' => $runs,
            ];
        }

        return ['units' => $units, 'totals' => $this->totals($units)];
    }

    /** @return Builder<UnitStatusRun> */
    private function counted(): Builder
    {
        return UnitStatusRun::query()->whereNull('voided_at')->where('confirmed', true);
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

    /**
     * The duty day with the most runs for each unit since tracking began; a tie goes to the latest date.
     *
     * @return array<string, array{runs: int, date: string, label: string}>
     */
    private function busiestDays(): array
    {
        $best = [];
        $rows = $this->counted()->selectRaw('unit_code, duty_date, count(*) as total')->groupBy('unit_code', 'duty_date')->get();
        foreach ($rows as $row) {
            $code = (string) $row->unit_code;
            $date = substr((string) $row->duty_date, 0, 10);
            $runs = (int) $row->getAttribute('total');
            $held = $best[$code] ?? null;
            if ($held === null || $runs > $held['runs'] || ($runs === $held['runs'] && $date > $held['date'])) {
                $best[$code] = ['runs' => $runs, 'date' => $date, 'label' => CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('M j, Y')];
            }
        }

        return $best;
    }

    /**
     * The runs of this duty date, newest first, per unit, with the call facts the pop-up shows.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function todayRuns(string $dutyDate): array
    {
        $runs = $this->counted()->where('duty_date', $dutyDate)->orderByDesc('attributed_at')->orderByDesc('id')->get();
        if ($runs->isEmpty()) {
            return [];
        }
        $ids = $runs->pluck('source_call_id')->all();
        $calls = [];
        foreach (UnitStatusCall::query()->whereIn('source_call_id', $ids)->get() as $call) {
            $calls[$call->source_call_id] = $call;
        }
        $statuses = [];
        foreach (UnitStatusCallUnit::query()->whereIn('source_call_id', $ids)->get(['source_call_id', 'unit_code', 'status']) as $row) {
            $statuses[$row->source_call_id.'|'.$row->unit_code] = $row->status;
        }

        $byUnit = [];
        foreach ($runs as $run) {
            $call = $calls[$run->source_call_id] ?? null;
            $externalId = $call !== null ? ($call->external_id ?? $run->external_id) : $run->external_id;
            $byUnit[$run->unit_code][] = [
                'sourceCallId' => $run->source_call_id,
                'attributedAt' => UnitStatusTime::iso($run->attributed_at),
                'timeLabel' => UnitStatusTime::time($run->attributed_at),
                'callType' => $call !== null ? $call->call_type : '',
                'idLabel' => $externalId !== null ? '#'.$externalId : 'ID pending',
                'externalId' => $externalId,
                'unitStatus' => StatusFamily::label($statuses[$run->source_call_id.'|'.$run->unit_code] ?? ''),
                'sourceUrl' => $call?->source_url,
            ];
        }

        return $byUnit;
    }

    /**
     * Rescue and combat runs this shift. Units of class by_call (CPT5, 300, FB6) count as rescue on a medical call and
     * as combat on any other. The busiest unit is the one with the most runs; ties list every tied unit.
     *
     * @param  array<string, array{shift: int, todayRuns: list<array<string, mixed>>}>  $units
     * @return array{total: int, rescue: int, combat: int, busiest: ?array{codes: list<string>, runs: int}}
     */
    private function totals(array $units): array
    {
        $rescue = 0;
        $combat = 0;
        foreach ($this->registry->units() as $unit) {
            foreach ($units[$unit->code]['todayRuns'] as $run) {
                $this->countsAsRescue($unit, (string) $run['callType']) ? $rescue++ : $combat++;
            }
        }

        $most = 0;
        $codes = [];
        foreach ($this->registry->units() as $unit) {
            $runs = $units[$unit->code]['shift'];
            if ($runs > $most) {
                [$most, $codes] = [$runs, [$unit->code]];
            } elseif ($runs === $most && $runs > 0) {
                $codes[] = $unit->code;
            }
        }

        return ['total' => $rescue + $combat, 'rescue' => $rescue, 'combat' => $combat, 'busiest' => $most > 0 ? ['codes' => $codes, 'runs' => $most] : null];
    }

    private function countsAsRescue(UnitDefinition $unit, string $callType): bool
    {
        return match ($unit->class) {
            UnitDefinition::RESCUE => true,
            UnitDefinition::COMBAT => false,
            default => preg_match((string) config('unit_status.medical_call_pattern'), $callType) === 1,
        };
    }
}
