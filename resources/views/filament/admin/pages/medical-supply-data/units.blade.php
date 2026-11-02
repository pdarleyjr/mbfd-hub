@use('App\Filament\Support\SupplyDataLabels', 'L')
@php $unitRows = array_values($overview['units'] ?? []); @endphp
<x-filament::section heading="Units" description="Which units asked for supplies and which were supplied from the supply room.">
    <p class="mb-3 text-xs font-medium text-gray-700 dark:text-gray-300">{{ $distributionNote }}</p>
    @if ($unitRows === [])
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="units-empty">
            No unit requested or received supplies in this period with these filters. Widen the time period or clear the unit filter.
        </p>
    @else
        <table data-hub-table class="w-full text-left text-sm" data-testid="supply-units">
            <caption class="sr-only">Units, with requests and distribution events</caption>
            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium first:pl-0">Unit</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Requests</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="1">Distribution events</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="3">Supplies distributed</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="4">Last replenished</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="1"><span class="sr-only">Details</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($unitRows as $row)
                    <tr wire:key="unit-{{ $row['unit_key'] }}">
                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</th>
                        <td class="supply-num px-3 py-2">{{ L::number($row['requests'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['distribution_events'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['items_distributed'] ?? null) }}</td>
                        <td class="px-3 py-2">{{ L::date($row['last_replenished_at'] ?? null) }}</td>
                        <td class="px-3 py-2">
                            <x-filament::button size="sm" color="gray" outlined class="min-h-[44px]" wire:click="mountAction('unitDetail', { unit: '{{ e($row['unit_key']) }}' })">
                                Details<span class="sr-only"> for {{ $row['label'] }}</span>
                            </x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">A dash under Last replenished means nothing has been distributed to that unit in this period.</p>
    @endif
</x-filament::section>
