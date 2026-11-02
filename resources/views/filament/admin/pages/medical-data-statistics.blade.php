{{-- Filament sections and form controls only. Layout utilities, no colour literals. --}}
<x-filament-panels::page>
    @include('filament.admin.partials.ems-styles')
    @include('filament.admin.pages.medical-supply-data.styles')
    <div class="space-y-6" data-testid="medical-statistics">
        <x-filament::tabs label="Medical supply data sections" data-testid="supply-tabs">
            @foreach ($tabs as $key => $label)
                <x-filament::tabs.item
                    :active="$activeTab === $key"
                    wire:click="showTab('{{ $key }}')"
                    wire:key="supply-tab-{{ $key }}"
                    aria-controls="supply-panel"
                    class="min-h-[44px]"
                >{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div id="supply-panel" role="tabpanel" class="space-y-6" data-testid="supply-panel-{{ $activeTab }}">
            @if ($activeTab === 'supply')
                @include('filament.admin.pages.medical-supply-data.supply')
            @else
                @include('filament.admin.pages.medical-supply-data.requests')
            @endif
        </div>
    </div>
</x-filament-panels::page>
