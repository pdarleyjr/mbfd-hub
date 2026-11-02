@use('App\Filament\Support\SupplyDataLabels', 'L')
{{-- Every figure here is from SupplyDataReport. This page formats it and never adds, averages or ranks anything itself. --}}
<div class="space-y-6" data-testid="supply-data">
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white" role="note" data-testid="supply-label">
        <p class="font-medium">{{ $overview['label'] ?? $distributionNote }}</p>
        <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">
            These figures show what left the supply room for each unit. They do not show what was used on patients or calls.
            Balances marked Estimated are best guesses until someone counts the shelf.
        </p>
    </div>

    @include('filament.admin.pages.medical-supply-data.filters')

    @if ($overview === null)
        <x-filament::section>
            <p class="text-sm text-gray-950 dark:text-white" data-testid="supply-blocked">
                Fix the filters above to see supply data. Nothing is hidden: the figures appear as soon as the filters are accepted.
            </p>
        </x-filament::section>
    @else
        @include('filament.admin.pages.medical-supply-data.attention')
        @include('filament.admin.pages.medical-supply-data.headline')
        @include('filament.admin.pages.medical-supply-data.trend')
        @include('filament.admin.pages.medical-supply-data.supplies')
        @include('filament.admin.pages.medical-supply-data.units')
        @include('filament.admin.pages.medical-supply-data.stock')
        @include('filament.admin.pages.medical-supply-data.fulfillment')
        @include('filament.admin.pages.medical-supply-data.count-accuracy')
        @include('filament.admin.pages.medical-supply-data.data-quality')
        @include('filament.admin.pages.medical-supply-data.definitions')
    @endif
</div>
