@use('App\Filament\Support\SupplyDataLabels', 'L')
@use('App\Filament\Support\EmsLabels', 'E')
{{-- One unit, from SupplyDataReport::unit(). Opened from the Units table. --}}
<div class="space-y-5" data-testid="supply-unit-detail">
    @if ($detail === null)
        <p class="text-sm text-gray-950 dark:text-white" data-testid="unit-detail-missing">
            @if ($problems !== [])
                Fix the filters on the page first, then open this unit again.
            @else
                This unit is not in the supply data. Its truck may no longer be on file.
            @endif
        </p>
    @else
        @php
            $summary = $detail['summary'] ?? [];
            $trend = array_values($detail['trend'] ?? []);
            $monthly = isset($trend[0]['month_start']);
            $startKey = $monthly ? 'month_start' : 'week_start';
            $period = $detail['period'] ?? [];
            $canOpenRequests = \App\Filament\Resources\InventoryRequestResource::canViewAny();
        @endphp

        @if (isset($period['from'], $period['to']))
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ L::date($period['from']) }} to {{ L::date($period['to']) }}</p>
        @endif

        <section aria-labelledby="unit-summary-heading">
            <h3 id="unit-summary-heading" class="text-sm font-semibold text-gray-950 dark:text-white">In this period</h3>
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $distributionNote }}</p>
            <dl class="supply-stats mt-2" data-testid="unit-summary">
                @foreach ([['requests', 'Requests'], ['requests_reconciled', 'Requests reconciled'], ['qty_requested', 'Quantity requested'], ['distributed_events', 'Distribution events'], ['items_distributed', 'Supplies distributed']] as [$key, $label])
                    @php $value = $summary[$key] ?? null; @endphp
                    <div class="supply-stat" wire:key="unit-summary-{{ $key }}">
                        <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                        <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($value) }}@if ($value === null)<span class="sr-only">Not available</span>@endif</dd>
                    </div>
                @endforeach
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Last replenished</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::date($summary['last_replenished_at'] ?? null) }}</dd>
                    @if (($summary['last_replenished_at'] ?? null) === null)
                        <dd class="text-xs text-gray-600 dark:text-gray-400">Nothing has been distributed to this unit in the period.</dd>
                    @endif
                </div>
            </dl>
        </section>

        <section aria-labelledby="unit-requested-heading">
            <h3 id="unit-requested-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Most requested</h3>
            @if (($detail['top_requested'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="unit-requested-empty">This unit asked for nothing in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="unit-top-requested">
                    <caption class="sr-only">Supplies this unit asked for most</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">Supply</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Quantity requested</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Requests</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['top_requested'] as $row)
                            <tr wire:key="unit-requested-{{ $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</th>
                                <td class="supply-num px-3 py-2">{{ L::number($row['qty_requested'] ?? null) }} {{ filled($row['unit'] ?? null) ? $row['unit'] : '' }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['requests'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="unit-distributed-heading">
            <h3 id="unit-distributed-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Most distributed to this unit</h3>
            @if (($detail['top_distributed'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="unit-distributed-empty">Nothing was distributed to this unit in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="unit-top-distributed">
                    <caption class="sr-only">Supplies distributed to this unit, most events first</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">Supply</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Distribution events</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Quantity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['top_distributed'] as $row)
                            <tr wire:key="unit-distributed-{{ $row['item_id'] }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</th>
                                <td class="supply-num px-3 py-2">{{ L::number($row['distribution_events'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['distributed'] ?? null) }} {{ L::unitWord($row['count_unit'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        @if ($trend !== [])
            <section aria-labelledby="unit-trend-heading">
                <h3 id="unit-trend-heading" class="text-sm font-semibold text-gray-950 dark:text-white">{{ $monthly ? 'Month by month' : 'Week by week' }}</h3>
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="unit-trend">
                    <caption class="sr-only">Requests and distribution events for this unit by {{ $monthly ? 'month' : 'week' }}</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">{{ $monthly ? 'Month starting' : 'Week starting' }}</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Requests</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Distribution events</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($trend as $bucket)
                            <tr wire:key="unit-trend-{{ $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::bucket((string) ($bucket[$startKey] ?? ''), $monthly) }}</th>
                                <td class="supply-num px-3 py-2">{{ L::number($bucket['requests'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($bucket['distribution_events'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section aria-labelledby="unit-requests-heading">
            <h3 id="unit-requests-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Requests</h3>
            @if (($detail['requests'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="unit-requests-empty">This unit sent no requests in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="unit-requests">
                    <caption class="sr-only">Newest counted requests from this unit</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">Request</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="2">Sent</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="3">Lines</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="4">Quantity</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="5">Reconciled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['requests'] as $row)
                            <tr wire:key="unit-request-{{ $row['uuid'] ?? $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">
                                    @if ($canOpenRequests && filled($row['uuid'] ?? null))
                                        <a href="{{ \App\Filament\Resources\InventoryRequestResource::getUrl('view', ['record' => $row['uuid']]) }}" class="text-primary-600 underline dark:text-primary-400">{{ $row['number'] }}</a>
                                    @else
                                        {{ $row['number'] }}
                                    @endif
                                </th>
                                <td class="px-3 py-2">{{ L::date($row['submitted_at'] ?? null) }}</td>
                                <td class="px-3 py-2"><x-filament::badge :color="E::STATUS_COLOR[$row['status'] ?? ''] ?? 'gray'" size="sm">{{ E::STATUS[$row['status'] ?? ''] ?? \Illuminate\Support\Str::headline((string) ($row['status'] ?? '')) }}</x-filament::badge></td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['lines'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['qty_requested'] ?? null) }}</td>
                                <td class="px-3 py-2">{{ L::date($row['reconciled_at'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">The newest 50 requests in the period. A dash under Reconciled means the request has not been reconciled.</p>
            @endif
        </section>
    @endif
</div>
