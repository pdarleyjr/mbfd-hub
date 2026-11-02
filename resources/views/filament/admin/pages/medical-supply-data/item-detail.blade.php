@use('App\Filament\Support\SupplyDataLabels', 'L')
{{-- One supply, from SupplyDataReport::item(). Opened from the Supplies and Stock tables. --}}
<div class="space-y-5" data-testid="supply-item-detail">
    @if ($detail === null)
        <p class="text-sm text-gray-950 dark:text-white" data-testid="item-detail-missing">
            @if ($problems !== [])
                Fix the filters on the page first, then open this supply again.
            @else
                This supply is not available. It may be inactive, or it may be a controlled item, which supply data never includes.
            @endif
        </p>
    @else
        @php
            $item = $detail['item'] ?? [];
            $balance = $detail['balance'] ?? [];
            $distribution = $detail['distribution'] ?? [];
            $unit = L::unitWord($item['count_unit'] ?? null);
            [$basisLabel, $basisColor, $basisMeaning] = L::basis($balance['basis'] ?? null);
            [$statusLabel, $statusColor] = L::status($balance['status'] ?? null);
            $period = $detail['period'] ?? [];
            $typeWords = ['issued' => 'Issued', 'received' => 'Received', 'adjusted' => 'Adjusted'];
            $trend = array_values($detail['trend'] ?? []);
            $monthly = isset($trend[0]['month_start']);
            $startKey = $monthly ? 'month_start' : 'week_start';
        @endphp

        <p class="text-xs text-gray-600 dark:text-gray-400">
            {{ collect([$item['category'] ?? null, $item['location'] ?? null])->filter()->implode(' · ') }}
            @if (isset($period['from'], $period['to']))
                {{ filled($item['category'] ?? null) || filled($item['location'] ?? null) ? ' · ' : '' }}{{ L::date($period['from']) }} to {{ L::date($period['to']) }}
            @endif
        </p>

        <section aria-labelledby="item-balance-heading">
            <h3 id="item-balance-heading" class="text-sm font-semibold text-gray-950 dark:text-white">On the shelf</h3>
            <dl class="supply-stats mt-2" data-testid="item-balance">
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">On hand</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($balance['on_hand'] ?? null) }} @if (($balance['on_hand'] ?? null) !== null)<span class="text-sm font-normal">{{ $unit }}</span>@else<span class="sr-only">Not counted yet</span>@endif</dd>
                    <dd class="mt-1"><x-filament::badge :color="$basisColor" size="sm">{{ $basisLabel }}</x-filament::badge></dd>
                    <dd class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $basisMeaning }}</dd>
                </div>
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Status</dt>
                    <dd class="mt-1"><x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge></dd>
                    <dd class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        Minimum: {{ L::number($balance['minimum'] ?? null) }}@if (($balance['minimum'] ?? null) === null) (none set)@endif
                    </dd>
                    <dd class="text-xs text-gray-600 dark:text-gray-400">
                        @if (($balance['needs_reorder'] ?? false) === true)
                            Reorder recommended
                            @if (isset($balance['recommended_order']))
                                (suggested {{ L::number($balance['recommended_order']) }}{{ filled($balance['recommended_order_unit'] ?? null) ? ' '.$balance['recommended_order_unit'] : '' }})
                            @endif
                        @else
                            No reorder needed
                        @endif
                    </dd>
                </div>
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Days of supply</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($balance['days_of_supply'] ?? null) }}@if (($balance['days_of_supply'] ?? null) === null)<span class="sr-only">Cannot be worked out</span>@endif</dd>
                    <dd class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        {{ ($balance['days_of_supply'] ?? null) === null ? 'Cannot be worked out: it needs a counted balance and at least three distributions in the period.' : 'At the pace of distribution in this period. Not patient use.' }}
                    </dd>
                    <dd class="text-xs text-gray-600 dark:text-gray-400">Last counted {{ L::date($balance['last_counted_at'] ?? null) }}@if (isset($balance['deductions_since_count'])), {{ L::number($balance['deductions_since_count']) }} {{ $unit }} issued since @endif</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="item-distribution-heading">
            <h3 id="item-distribution-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Distribution in this period</h3>
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $distributionNote }}</p>
            <dl class="supply-stats mt-2" data-testid="item-distribution">
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Quantity distributed</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($distribution['distributed'] ?? null) }} <span class="text-sm font-normal">{{ $unit }}</span></dd>
                </div>
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Distribution events</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($distribution['distribution_events'] ?? null) }}</dd>
                    <dd class="text-xs text-gray-600 dark:text-gray-400">
                        {{ L::number($distribution['previous_distribution_events'] ?? null) }} in the previous period
                        @if (($distribution['change_pct'] ?? null) !== null)
                            {{ L::percent($distribution['change_pct']) }} change.
                        @endif
                    </dd>
                </div>
                <div class="supply-stat">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Average per week</dt>
                    <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($distribution['avg_weekly'] ?? null) }}</dd>
                    <dd class="text-xs text-gray-600 dark:text-gray-400">
                        Middle week: {{ L::number($distribution['median_weekly'] ?? null) }}.
                        @if (($distribution['median_weekly'] ?? null) === null)
                            The middle week is not shown for month-by-month periods.
                        @endif
                        Last distributed {{ L::date($distribution['last_distributed_at'] ?? null) }}.
                    </dd>
                </div>
            </dl>
        </section>

        @if ($trend !== [])
            <section aria-labelledby="item-trend-heading">
                <h3 id="item-trend-heading" class="text-sm font-semibold text-gray-950 dark:text-white">{{ $monthly ? 'Month by month' : 'Week by week' }}</h3>
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="item-trend">
                    <caption class="sr-only">Distribution of this supply by {{ $monthly ? 'month' : 'week' }}</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">{{ $monthly ? 'Month starting' : 'Week starting' }}</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Quantity ({{ $unit }})</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Distribution events</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($trend as $bucket)
                            <tr wire:key="item-trend-{{ $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::bucket((string) ($bucket[$startKey] ?? ''), $monthly) }}</th>
                                <td class="supply-num px-3 py-2">{{ L::number($bucket['distributed'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($bucket['distribution_events'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section aria-labelledby="item-units-heading">
            <h3 id="item-units-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Units that received it</h3>
            @if (($detail['units'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="item-units-empty">No unit received this supply in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="item-units">
                    <caption class="sr-only">Units that received this supply</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">Unit</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Distribution events</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Quantity ({{ $unit }})</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Share</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['units'] as $row)
                            <tr wire:key="item-unit-{{ $row['unit_key'] }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</th>
                                <td class="supply-num px-3 py-2">{{ L::number($row['distribution_events'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['distributed'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ ($row['share_pct'] ?? null) === null ? L::DASH : L::number($row['share_pct']).'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="item-replenishment-heading">
            <h3 id="item-replenishment-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Received and adjusted</h3>
            @if (($detail['replenishment'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="item-replenishment-empty">Nothing was received or adjusted in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="item-replenishment">
                    <caption class="sr-only">Stock received and adjusted</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">When</th>
                            <th scope="col" class="px-3 py-2 font-medium">What</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Quantity ({{ $unit }})</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="2">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['replenishment'] as $row)
                            <tr wire:key="item-replenishment-{{ $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::dateTime($row['occurred_at'] ?? null) }}</th>
                                <td class="px-3 py-2">{{ $typeWords[$row['type'] ?? ''] ?? \Illuminate\Support\Str::headline((string) ($row['type'] ?? '')) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['quantity'] ?? null) }}</td>
                                <td class="px-3 py-2">{{ filled($row['reason'] ?? null) ? $row['reason'] : L::DASH }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="item-counts-heading">
            <h3 id="item-counts-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Physical counts</h3>
            @if (($detail['counts'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="item-counts-empty">This supply has not been counted yet.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="item-counts">
                    <caption class="sr-only">Counts of this supply, newest first</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">Counted on</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Counted</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Expected</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Difference</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="3">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['counts'] as $row)
                            <tr wire:key="item-count-{{ $loop->index }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::date($row['counted_at'] ?? null) }}</th>
                                <td class="supply-num px-3 py-2">
                                    @if (($row['verified'] ?? null) !== null)
                                        {{ L::number($row['verified']) }}
                                    @elseif (($row['estimated'] ?? null) !== null)
                                        {{ L::number($row['estimated']) }} <x-filament::badge color="warning" size="sm">Estimated</x-filament::badge>
                                    @else
                                        {{ L::DASH }}
                                    @endif
                                </td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['expected'] ?? null) }}</td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['variance'] ?? null) }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ filled($row['basis_note'] ?? null) ? $row['basis_note'] : (($row['variance'] ?? null) === null ? 'No difference is shown: the starting figure was not a physical count.' : L::DASH) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="item-movements-heading">
            <h3 id="item-movements-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Stock movements</h3>
            @if (($detail['movements'] ?? []) === [])
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="item-movements-empty">No stock moved in this period.</p>
            @else
                <table data-hub-table class="mt-2 w-full text-left text-sm" data-testid="item-movements">
                    <caption class="sr-only">Newest stock movements for this supply in the period</caption>
                    <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium first:pl-0">When</th>
                            <th scope="col" class="px-3 py-2 font-medium">What</th>
                            <th scope="col" class="supply-num px-3 py-2 font-medium">Quantity ({{ $unit }})</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="2">Unit</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="3">Request</th>
                            <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="4">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($detail['movements'] as $row)
                            <tr wire:key="item-movement-{{ $row['id'] }}">
                                <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::dateTime($row['occurred_at'] ?? null) }}</th>
                                <td class="px-3 py-2">
                                    {{ $typeWords[$row['type'] ?? ''] ?? \Illuminate\Support\Str::headline((string) ($row['type'] ?? '')) }}
                                    @if (($row['reversed'] ?? false) === true)<x-filament::badge color="gray" size="sm">Reversed</x-filament::badge>@endif
                                </td>
                                <td class="supply-num px-3 py-2">{{ L::number($row['quantity'] ?? null) }}</td>
                                <td class="px-3 py-2">{{ filled($row['unit_label'] ?? null) ? $row['unit_label'] : L::DASH }}</td>
                                <td class="px-3 py-2">{{ filled($row['request_number'] ?? null) ? $row['request_number'] : L::DASH }}</td>
                                <td class="px-3 py-2">{{ filled($row['reason'] ?? null) ? $row['reason'] : L::DASH }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (($detail['movements_truncated'] ?? false) === true)
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400" data-testid="item-movements-truncated">Only the newest 50 movements in the period are listed.</p>
                @endif
            @endif
        </section>
    @endif
</div>
