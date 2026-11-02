@use('App\Filament\Support\SupplyDataLabels', 'L')
<x-filament::section heading="Stock" description="What is on the shelf now, and how that figure is known. Balances do not change with the time period." id="supply-stock">
    <div class="mb-3 flex flex-wrap items-center gap-x-6 gap-y-2">
        <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-950 dark:text-white">
            <x-filament::input.checkbox wire:model.live="stockLowOnly" data-testid="stock-low-only" />
            <span>Only supplies that are low or need reordering</span>
        </label>
        <p class="text-xs text-gray-600 dark:text-gray-400" data-testid="stock-count">
            Showing {{ min(count($stockRows), $stockTotal) }} of {{ $stockTotal }} {{ $stockTotal === 1 ? 'supply' : 'supplies' }}.
        </p>
    </div>
    @if ($stockRows === [])
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="stock-empty">
            @if ($stockLowOnly)
                No supply is low or waiting to be reordered with these filters.
            @else
                No stocked supplies match these filters. Clear the category or supply filter to see more.
            @endif
        </p>
    @else
        <table data-hub-table class="w-full text-left text-sm" data-testid="supply-stock-table">
            <caption class="sr-only">Stock on hand, with how each balance is known</caption>
            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium first:pl-0">Supply</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="1">On hand</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="1">How we know</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="2">Status</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="4">Minimum</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="3">Reorder</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="5">Days of supply</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="6">Last counted</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="1"><span class="sr-only">Details</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($stockRows as $row)
                    @php
                        [$basisLabel, $basisColor, $basisMeaning] = L::basis($row['basis'] ?? null);
                        [$statusLabel, $statusColor] = L::status($row['status'] ?? null);
                    @endphp
                    <tr wire:key="stock-{{ $row['item_id'] }}">
                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">
                            {{ $row['name'] }}
                            <span class="block text-xs font-normal text-gray-600 dark:text-gray-400">{{ collect([$row['category'] ?? null, $row['location'] ?? null])->filter()->implode(' · ') }}</span>
                        </th>
                        <td class="supply-num px-3 py-2">
                            {{ L::number($row['on_hand'] ?? null) }}
                            @if (($row['on_hand'] ?? null) !== null) {{ L::unitWord($row['count_unit'] ?? null) }}@else<span class="sr-only">Not counted yet</span>@endif
                        </td>
                        <td class="px-3 py-2">
                            <x-filament::badge :color="$basisColor" size="sm" :title="$basisMeaning">{{ $basisLabel }}</x-filament::badge>
                            @if (($row['basis'] ?? null) === 'estimate')
                                <span class="block text-xs text-gray-600 dark:text-gray-400">Not a physical count</span>
                            @endif
                        </td>
                        <td class="px-3 py-2"><x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge></td>
                        <td class="supply-num px-3 py-2">
                            {{ L::number($row['minimum'] ?? null) }}
                            @if (isset($row['order_level']))<span class="block text-xs text-gray-600 dark:text-gray-400">Order at {{ L::number($row['order_level']) }}</span>@endif
                        </td>
                        <td class="px-3 py-2">
                            @if (($row['needs_reorder'] ?? false) === true)
                                <x-filament::badge color="warning" size="sm">Reorder</x-filament::badge>
                                @if (isset($row['recommended_order']))<span class="block text-xs text-gray-600 dark:text-gray-400">Suggested: {{ L::number($row['recommended_order']) }}{{ filled($row['recommended_order_unit'] ?? null) ? ' '.$row['recommended_order_unit'] : '' }}</span>@endif
                            @else
                                <span class="text-gray-600 dark:text-gray-400">Not needed</span>
                            @endif
                        </td>
                        <td class="supply-num px-3 py-2">
                            {{ L::number($row['days_of_supply'] ?? null) }}
                            @if (($row['days_of_supply'] ?? null) === null)<span class="sr-only">Cannot be worked out</span>@endif
                        </td>
                        <td class="px-3 py-2">
                            {{ L::date($row['last_counted_at'] ?? null) }}
                            @if (isset($row['deductions_since_count']))<span class="block text-xs text-gray-600 dark:text-gray-400">{{ L::number($row['deductions_since_count']) }} {{ L::unitWord($row['count_unit'] ?? null) }} issued since</span>@endif
                        </td>
                        <td class="px-3 py-2">
                            <x-filament::button size="sm" color="gray" outlined class="min-h-[44px]" wire:click="mountAction('itemDetail', { item: {{ (int) $row['item_id'] }} })">
                                Details<span class="sr-only"> for {{ $row['name'] }}</span>
                            </x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400" data-testid="stock-legend">
            A dash means the figure cannot be worked out yet.
            {{ $overview['definitions']['days_of_supply'] ?? 'Days of supply is shown only when the balance was counted and there is enough distribution in the period to measure a pace; it is not patient use.' }}
        </p>
        @if ($stockTotal > count($stockRows))
            <div class="mt-3">
                <x-filament::button color="gray" outlined class="min-h-[44px]" wire:click="showMoreStock">Show more supplies</x-filament::button>
            </div>
        @endif
    @endif
</x-filament::section>
