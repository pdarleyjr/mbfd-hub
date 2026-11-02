@use('App\Filament\Support\SupplyDataLabels', 'L')
<x-filament::section heading="Needs attention" description="Counts of supplies as they stand now. They follow the category and supply filters, not the time period.">
    <dl class="supply-stats supply-stats-5" data-testid="supply-attention">
        @foreach (L::ATTENTION as $key => [$label, $meaning])
            @php
                $value = $overview['attention'][$key] ?? null;
                $urgent = in_array($key, ['zero_stock', 'below_minimum'], true) && is_int($value) && $value > 0;
            @endphp
            <div class="supply-stat {{ $urgent ? 'supply-stat-hot' : '' }}" wire:key="attention-{{ $key }}" data-testid="attention-{{ $key }}">
                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                <dd class="supply-stat-value text-gray-950 dark:text-white">
                    {{ L::number($value) }}
                    @if ($value === null)<span class="sr-only">Cannot be worked out</span>@endif
                </dd>
                <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                    {{ $value === null ? 'Cannot be worked out yet, so no number is shown.' : $meaning }}
                </dd>
            </div>
        @endforeach
    </dl>
</x-filament::section>
