<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Medical Data / Statistics, read from the transactional request tables. These numbers describe
 * what members REQUESTED and what was ISSUED against those requests. They are requests, not
 * consumption: nothing here measures what crews actually used.
 */
final class EmsStatistics
{
    public const LABEL = 'Requests, not consumption';

    public const DEFINITIONS = [
        'requests_submitted' => 'Requests submitted in the date range, in any status (cancelled ones included).',
        'requests_cancelled' => 'Requests the member cancelled before anything was issued.',
        'requests_counted' => 'Requests submitted in the range that were not cancelled, sent back or replaced by a correction. Every other figure below uses only these requests.',
        'lines_requested' => 'Items asked for on counted requests: a quantity of 1 or more, or a Yes answer. A "No" answer is not counted.',
        'qty_requested' => 'The sum of the quantities members asked for (Yes/No answers are not quantities).',
        'yes_answers' => 'Items answered Yes (for example the narcotics box). They are recorded only; narcotics are restocked by the controlled-substance procedure.',
        'qty_issued' => 'The sum of the quantities issued against counted requests, after corrections.',
        'qty_outstanding' => 'Quantity still owed right now: requested minus issued for items not marked "not issued", on requests that are not closed or cancelled. Never below zero per item.',
        'requests_per_week' => 'Counted requests divided by the weeks in the range (days in the range / 7, at least one day). Without a start date the range starts at the first request; without an end date it ends now.',
        'top_requested_items' => 'The ten items with the largest requested quantity (Yes answers count as 1), with the number of requests that included them.',
        'monthly_trend' => 'Counted requests, requested quantity and issued quantity by calendar month of submission (Eastern time).',
        'detail_special_event' => 'Counted requests for Detail / Special Event, and how often each typed event or assignment appears.',
        'by_unit' => 'The same requested and issued figures grouped by the unit the request was for, or Detail / Special Event.',
    ];

    /**
     * @param  array<string, mixed>  $filters  apparatus_id, target_kind, date_from, date_to (Y-m-d, Eastern)
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $tz = StockLedger::TIMEZONE;
        $from = ! empty($filters['date_from']) ? CarbonImmutable::parse((string) $filters['date_from'], $tz)->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? CarbonImmutable::parse((string) $filters['date_to'], $tz)->endOfDay() : null;

        $base = fn (): Builder => InventoryRequest::query()
            ->when($from !== null, fn ($q) => $q->where('submitted_at', '>=', $from->utc()))
            ->when($to !== null, fn ($q) => $q->where('submitted_at', '<=', $to->utc()))
            ->when(! empty($filters['apparatus_id']), fn ($q) => $q->where('apparatus_id', (int) $filters['apparatus_id']))
            ->when(! empty($filters['target_kind']) && in_array($filters['target_kind'], InventoryRequest::TARGET_KINDS, true), fn ($q) => $q->where('target_kind', (string) $filters['target_kind']));

        $requests = $base()->get(['id', 'status', 'submitted_at', 'target_kind', 'apparatus_id', 'target_label', 'event_assignment']);
        // A form sent back or replaced by its correction would count the same supplies twice.
        $notCounted = [InventoryRequest::CANCELLED, InventoryRequest::RETURNED, InventoryRequest::SUPERSEDED];
        $counted = $requests->whereNotIn('status', $notCounted)->values();
        $lines = $counted->isEmpty() ? collect() : InventoryRequestLine::query()
            ->whereIn('request_id', $base()->whereNotIn('status', $notCounted)->select('id'))
            ->get(['id', 'request_id', 'bag_key', 'item_key', 'label', 'response_type', 'requested_qty', 'requested_flag', 'issued_qty', 'line_status']);
        $requestById = $counted->keyBy('id');
        $quantityLines = $lines->where('response_type', 'quantity')->values();
        $asked = $lines->filter(fn (InventoryRequestLine $l): bool => $l->wantedQty() > 0)->values();

        $weeks = $this->weeks($requests, $from, $to);

        return [
            'label' => self::LABEL,
            'filters' => [
                'apparatus_id' => ! empty($filters['apparatus_id']) ? (int) $filters['apparatus_id'] : null,
                'target_kind' => $filters['target_kind'] ?? null,
                'date_from' => $from?->format('Y-m-d'), 'date_to' => $to?->format('Y-m-d'),
            ],
            'totals' => [
                'requests_submitted' => $requests->count(),
                'requests_cancelled' => $requests->where('status', InventoryRequest::CANCELLED)->count(),
                'requests_counted' => $counted->count(),
                'lines_requested' => $asked->count(),
                'qty_requested' => (int) $asked->where('response_type', 'quantity')->sum('requested_qty'),
                'yes_answers' => $asked->where('response_type', 'yes_no')->count(),
                'qty_issued' => (int) $quantityLines->sum('issued_qty'),
                'qty_outstanding' => $this->outstanding($asked, $requestById),
                'weeks_in_range' => round($weeks, 2),
                'requests_per_week' => round($counted->count() / $weeks, 2),
            ],
            'top_requested_items' => $this->topItems($asked),
            'monthly_trend' => $this->monthly($counted, $asked, $quantityLines),
            'detail_special_event' => $this->detail($counted),
            'by_unit' => $this->byUnit($counted, $asked, $quantityLines),
            'definitions' => self::DEFINITIONS,
        ];
    }

    /** @param Collection<int, InventoryRequest> $requests */
    private function weeks(Collection $requests, ?CarbonImmutable $from, ?CarbonImmutable $to): float
    {
        $start = $from ?? ($requests->isEmpty() ? null : CarbonImmutable::parse($requests->min('submitted_at'))->setTimezone(StockLedger::TIMEZONE)->startOfDay());
        $end = $to ?? CarbonImmutable::now(StockLedger::TIMEZONE);
        $days = $start === null ? 1 : max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);

        return $days / 7;
    }

    /**
     * @param  Collection<int, InventoryRequestLine>  $asked
     * @param  Collection<int, InventoryRequest>  $requestById
     */
    private function outstanding(Collection $asked, Collection $requestById): int
    {
        return (int) $asked
            ->filter(fn (InventoryRequestLine $l): bool => $l->line_status !== InventoryRequestLine::NOT_ISSUED
                && in_array($requestById->get($l->request_id)?->status, InventoryRequest::OPEN_STATUSES, true))
            ->sum(fn (InventoryRequestLine $l): int => max(0, $l->wantedQty() - (int) $l->issued_qty));
    }

    /**
     * @param  Collection<int, InventoryRequestLine>  $asked
     * @return list<array<string, mixed>>
     */
    private function topItems(Collection $asked): array
    {
        return $asked->groupBy(fn (InventoryRequestLine $l): string => $l->bag_key.'|'.$l->item_key)
            ->map(fn (Collection $group): array => [
                'bag_key' => $group->first()->bag_key, 'item_key' => $group->first()->item_key, 'label' => $group->last()->label,
                'qty_requested' => (int) $group->sum(fn (InventoryRequestLine $l): int => $l->wantedQty()),
                'qty_issued' => (int) $group->sum('issued_qty'),
                'requests' => $group->pluck('request_id')->unique()->count(),
            ])
            ->sortBy([['qty_requested', 'desc'], ['requests', 'desc'], ['label', 'asc']])
            ->take(10)->values()->all();
    }

    /**
     * @param  Collection<int, InventoryRequest>  $counted
     * @param  Collection<int, InventoryRequestLine>  $asked
     * @param  Collection<int, InventoryRequestLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function monthly(Collection $counted, Collection $asked, Collection $lines): array
    {
        if ($counted->isEmpty()) {
            return [];
        }
        $month = fn (InventoryRequest $r): string => CarbonImmutable::parse($r->submitted_at)->setTimezone(StockLedger::TIMEZONE)->format('Y-m');
        $monthOf = $counted->mapWithKeys(fn (InventoryRequest $r): array => [$r->getKey() => $month($r)]);
        $rows = [];
        foreach ($counted->map($month)->unique()->sort()->values() as $key) {
            $ids = $monthOf->filter(fn (string $m): bool => $m === $key)->keys()->all();
            $rows[$key] = [
                'month' => $key, 'requests' => count($ids),
                'qty_requested' => (int) $asked->whereIn('request_id', $ids)->where('response_type', 'quantity')->sum('requested_qty'),
                'qty_issued' => (int) $lines->whereIn('request_id', $ids)->sum('issued_qty'),
            ];
        }
        // Months with no requests still appear, so a gap reads as zero rather than as a missing bar.
        $first = CarbonImmutable::createFromFormat('Y-m', array_key_first($rows), StockLedger::TIMEZONE)->startOfMonth();
        $last = CarbonImmutable::createFromFormat('Y-m', array_key_last($rows), StockLedger::TIMEZONE)->startOfMonth();
        $filled = [];
        for ($cursor = $first; $cursor <= $last; $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $filled[] = $rows[$key] ?? ['month' => $key, 'requests' => 0, 'qty_requested' => 0, 'qty_issued' => 0];
        }

        return $filled;
    }

    /**
     * @param  Collection<int, InventoryRequest>  $counted
     * @return array<string, mixed>
     */
    private function detail(Collection $counted): array
    {
        $details = $counted->where('target_kind', InventoryRequest::TARGET_DETAIL);
        $named = $details->filter(fn (InventoryRequest $r): bool => trim((string) $r->event_assignment) !== '');

        return [
            'requests' => $details->count(),
            'without_event_text' => $details->count() - $named->count(),
            'events' => $named->groupBy(fn (InventoryRequest $r): string => mb_strtolower(trim((string) $r->event_assignment)))
                ->map(fn (Collection $group): array => ['event_assignment' => trim((string) $group->first()->event_assignment), 'requests' => $group->count()])
                ->sortByDesc('requests')->values()->take(20)->all(),
        ];
    }

    /**
     * @param  Collection<int, InventoryRequest>  $counted
     * @param  Collection<int, InventoryRequestLine>  $asked
     * @param  Collection<int, InventoryRequestLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function byUnit(Collection $counted, Collection $asked, Collection $lines): array
    {
        return $counted->groupBy(fn (InventoryRequest $r): string => $r->apparatus_id !== null ? 'unit:'.$r->apparatus_id : 'detail')
            ->map(function (Collection $group): array {
                return ['group' => $group];
            })->map(function (array $row) use ($asked, $lines): array {
                $ids = $row['group']->pluck('id')->all();
                $first = $row['group']->first();

                return [
                    'apparatus_id' => $first->apparatus_id, 'target_kind' => $first->target_kind, 'label' => $first->target_label,
                    'requests' => count($ids),
                    'qty_requested' => (int) $asked->whereIn('request_id', $ids)->where('response_type', 'quantity')->sum('requested_qty'),
                    'qty_issued' => (int) $lines->whereIn('request_id', $ids)->sum('issued_qty'),
                ];
            })->sortByDesc('requests')->values()->all();
    }
}
