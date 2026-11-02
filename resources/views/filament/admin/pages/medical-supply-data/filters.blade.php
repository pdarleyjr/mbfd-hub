@use('App\Filament\Support\SupplyDataLabels', 'L')
<x-filament::section heading="Filters" description="Dates are Eastern time and both ends are included.">
    <div class="supply-filters" data-testid="supply-filters">
        <div>
            <label for="supply-period" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Time period</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="supply-period" wire:model.live="supplyPeriod" class="min-h-[44px]">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        @if ($supplyPeriod === 'custom')
            <div>
                <label for="supply-from" class="block text-xs font-medium text-gray-600 dark:text-gray-400">From</label>
                <x-filament::input.wrapper><x-filament::input id="supply-from" type="date" min="{{ $earliestCustomFrom }}" max="{{ $todayEastern }}" wire:model.live.debounce.600ms="supplyFrom" class="min-h-[44px]" /></x-filament::input.wrapper>
            </div>
            <div>
                <label for="supply-to" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Until</label>
                <x-filament::input.wrapper><x-filament::input id="supply-to" type="date" max="{{ $todayEastern }}" wire:model.live.debounce.600ms="supplyTo" class="min-h-[44px]" /></x-filament::input.wrapper>
            </div>
        @endif
        <div>
            <label for="supply-unit" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Unit</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="supply-unit" wire:model.live="supplyUnit" class="min-h-[44px]">
                    <option value="">All units</option>
                    @foreach ($supplyUnits as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div>
            <label for="supply-category" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Category</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="supply-category" wire:model.live="supplyCategory" class="min-h-[44px]">
                    <option value="">All categories</option>
                    @foreach ($supplyCategories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div>
            <label for="supply-item" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Supply</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="supply-item" wire:model.live="supplyItem" class="min-h-[44px]">
                    <option value="">All supplies</option>
                    @foreach ($supplyItems as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <x-filament::button size="sm" color="gray" outlined wire:click="resetSupplyFilters" class="min-h-[44px]">Reset filters</x-filament::button>
        @if ($overview !== null)
            @php $period = $overview['period'] ?? []; @endphp
            <p class="text-xs text-gray-600 dark:text-gray-400" data-testid="supply-period-text">
                Showing {{ L::date($period['from'] ?? null) }} to {{ L::date($period['to'] ?? null) }}@if (isset($period['days'])) ({{ $period['days'] }} {{ $period['days'] === 1 ? 'day' : 'days' }})@endif.
                @if (isset($period['previous']['from'], $period['previous']['to']))
                    Compared with {{ L::date($period['previous']['from']) }} to {{ L::date($period['previous']['to']) }}.
                @endif
            </p>
        @endif
    </div>
    @foreach (($overview['scope_notes'] ?? []) as $note)
        <p class="mt-2 text-xs text-gray-700 dark:text-gray-300" data-testid="supply-scope-note">{{ $note }}</p>
    @endforeach
    <div role="alert" aria-live="polite" data-testid="supply-problems">
        @foreach ($supplyProblems as $problem)
            <p class="mt-2 text-sm font-medium text-danger-600">{{ $problem }}</p>
        @endforeach
    </div>
</x-filament::section>
