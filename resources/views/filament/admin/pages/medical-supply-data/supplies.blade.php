@use('App\Filament\Support\SupplyDataLabels', 'L')
@php $topItems = array_values($overview['top_items'] ?? []); @endphp
<x-filament::section heading="Supplies" description="Ranked by distribution events, up to 50 supplies. Quantities are in each supply's own count unit, so they are not added together.">
    <p class="mb-3 text-xs font-medium text-gray-700 dark:text-gray-300" data-testid="supplies-note">{{ $distributionNote }}</p>
    @if ($topItems === [])
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="supplies-empty">
            No supplies were distributed in this period with these filters. Widen the time period or clear the unit, category and supply filters.
        </p>
    @else
        <table data-hub-table class="w-full text-left text-sm" data-testid="supply-top-items">
            <caption class="sr-only">Supplies ranked by distribution events</caption>
            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium first:pl-0">Supply</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="1">Distribution events</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Quantity distributed</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="4">Units supplied</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="5">Last distributed</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="3">Compared with before</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="1"><span class="sr-only">Details</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($topItems as $row)
                    @php [$directionLabel, $directionColor] = L::direction($row['direction'] ?? null); @endphp
                    <tr wire:key="top-item-{{ $row['item_id'] }}">
                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">
                            {{ $row['name'] }}
                            @if (filled($row['category'] ?? null))<span class="block text-xs font-normal text-gray-600 dark:text-gray-400">{{ $row['category'] }}</span>@endif
                        </th>
                        <td class="supply-num px-3 py-2">{{ L::number($row['distribution_events'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['distributed'] ?? null) }} {{ L::unitWord($row['count_unit'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['units_supplied'] ?? null) }}</td>
                        <td class="px-3 py-2">{{ L::date($row['last_distributed_at'] ?? null) }}</td>
                        <td class="px-3 py-2">
                            <x-filament::badge :color="$directionColor" size="sm">{{ $directionLabel }}</x-filament::badge>
                            @if (($row['direction'] ?? null) !== 'new' && isset($row['previous_distribution_events']))
                                <span class="block text-xs text-gray-600 dark:text-gray-400">{{ L::number($row['previous_distribution_events']) }} before</span>
                            @endif
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
    @endif
</x-filament::section>
