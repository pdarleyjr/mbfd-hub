@use('App\Filament\Support\SupplyDataLabels', 'L')
@php
    $accuracy = $overview['count_accuracy'] ?? [];
    $differences = array_values($accuracy['items'] ?? []);
    $tiles = [
        ['counted_lines', 'Counts finished', 'Supply lines physically counted in this period.', null],
        ['comparable_counts', 'Counts that can be compared', 'Counts whose starting figure was itself a physical count.', null],
        ['exact_matches', 'Matched the books exactly', 'Comparable counts with no difference.', 'No count could be compared yet, so there is nothing to match.'],
        ['items_compared', 'Supplies compared', 'Different supplies in those comparable counts.', null],
        ['coverage_pct', 'Share of supplies compared', 'Supplies compared as a share of supplies tracked.', 'Cannot be worked out until a count has a verified starting figure.'],
    ];
@endphp
<x-filament::section heading="Count accuracy" description="How closely physical counts matched what the books expected. An estimate is never used as the thing to match." collapsible collapsed>
    @if (filled($accuracy['note'] ?? null))
        <p class="mb-3 text-sm text-gray-950 dark:text-white" data-testid="count-accuracy-note">{{ $accuracy['note'] }}</p>
    @endif
    <dl class="supply-stats" data-testid="supply-count-accuracy">
        @foreach ($tiles as [$key, $label, $meaning, $whyEmpty])
            @php $value = $accuracy[$key] ?? null; @endphp
            <div class="supply-stat" wire:key="accuracy-{{ $key }}">
                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                <dd class="supply-stat-value text-gray-950 dark:text-white">
                    {{ $key === 'coverage_pct' && $value !== null ? L::number($value).'%' : L::number($value) }}
                    @if ($value === null)<span class="sr-only">Not available</span>@endif
                </dd>
                <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $value === null && $whyEmpty !== null ? $whyEmpty : $meaning }}</dd>
            </div>
        @endforeach
    </dl>
    @if ($differences !== [])
        <table data-hub-table class="mt-4 w-full text-left text-sm" data-testid="count-differences">
            <caption class="sr-only">Largest differences between counts and what the books expected</caption>
            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium first:pl-0">Supply</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="1">Counted</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="2">Expected</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="1">Difference</th>
                    <th scope="col" class="supply-num px-3 py-2 font-medium" data-hub-priority="3">Difference %</th>
                    <th scope="col" class="px-3 py-2 font-medium" data-hub-priority="4">Counted on</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($differences as $row)
                    <tr wire:key="difference-{{ $row['item_id'] }}-{{ $loop->index }}">
                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</th>
                        <td class="supply-num px-3 py-2">{{ L::number($row['counted'] ?? $row['verified'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['expected'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ L::number($row['variance'] ?? null) }}</td>
                        <td class="supply-num px-3 py-2">{{ ($row['variance_pct'] ?? null) === null ? L::DASH : L::number($row['variance_pct']).'%' }}</td>
                        <td class="px-3 py-2">{{ L::date($row['counted_at'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament::section>
