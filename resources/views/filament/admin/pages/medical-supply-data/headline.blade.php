@use('App\Filament\Support\SupplyDataLabels', 'L')
@php
    $headline = $overview['headline'] ?? [];
    $tiles = [
        ['distribution_events', 'Distribution events', 'Times a supply was issued to a unit on a request. '.$distributionNote.'.'],
        ['items_distributed', 'Supplies distributed', 'Different supplies that left the supply room.'],
        ['units_supplied', 'Units supplied', 'Units and details that received supplies.'],
        ['requests_submitted', 'Requests submitted', 'Medical Inventory Requests sent in this period.'],
        ['requests_reconciled', 'Requests reconciled', 'Requests whose supplies were deducted from the supply room and filed.'],
    ];
    $change = $headline['change_pct'] ?? null;
    $previous = $headline['previous_distribution_events'] ?? null;
    $comparisonNote = collect($overview['data_quality']['insufficient_data'] ?? [])->firstWhere('section', 'comparison')['message'] ?? null;
@endphp
<x-filament::section heading="This period" :description="$distributionNote">
    <dl class="supply-stats" data-testid="supply-headline">
        @foreach ($tiles as [$key, $label, $meaning])
            <div class="supply-stat" wire:key="headline-{{ $key }}" data-testid="headline-{{ $key }}">
                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                <dd class="supply-stat-value text-gray-950 dark:text-white">
                    {{ L::number($headline[$key] ?? null) }}
                    @if (($headline[$key] ?? null) === null)<span class="sr-only">Not available</span>@endif
                </dd>
                <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $meaning }}</dd>
                @if ($key === 'distribution_events')
                    <dd class="mt-1 text-xs text-gray-700 dark:text-gray-300" data-testid="headline-change">
                        @if ($change !== null)
                            {{ L::percent($change) }} compared with {{ L::number($previous) }} in the previous period.
                        @elseif ($previous === 0)
                            {{ $comparisonNote ?? 'Nothing was distributed in the previous period, so there is no percentage to show.' }}
                        @else
                            {{ L::DASH }} The previous period cannot be compared yet.
                        @endif
                    </dd>
                @endif
            </div>
        @endforeach
    </dl>
</x-filament::section>
