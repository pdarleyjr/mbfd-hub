<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-truck class="w-5 h-5 text-primary-500" />
                Fleet Snapshot
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="xs" icon="heroicon-o-arrow-path" outlined>
                Refresh
            </x-filament::button>
        </x-slot>

        @if($fleetData)
            <div class="space-y-4">
                {{-- Total --}}
                <div class="text-center pb-3 border-b border-gray-100">
                    <div class="text-3xl font-bold text-gray-900">{{ $fleetData['total'] }}</div>
                    <div class="text-sm text-gray-500">Total Apparatus</div>
                </div>

                {{-- Status breakdown --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="text-center p-2 rounded-lg bg-success-50">
                        <div class="text-lg font-semibold text-success-700">{{ $fleetData['in_service'] }}</div>
                        <div class="text-xs text-success-600">In Service</div>
                        <div class="text-xs text-success-500">{{ $fleetData['in_service_pct'] }}%</div>
                    </div>
                    
                    <div class="text-center p-2 rounded-lg bg-danger-50">
                        <div class="text-lg font-semibold text-danger-700">{{ $fleetData['out_of_service'] }}</div>
                        <div class="text-xs text-danger-600">Out of Service</div>
                        <div class="text-xs text-danger-500">{{ $fleetData['out_of_service_pct'] }}%</div>
                    </div>
                    
                    <div class="text-center p-2 rounded-lg bg-warning-50">
                        <div class="text-lg font-semibold text-warning-700">{{ $fleetData['maintenance'] }}</div>
                        <div class="text-xs text-warning-600">Maintenance</div>
                    </div>
                    
                    <div class="text-center p-2 rounded-lg bg-gray-50">
                        <div class="text-lg font-semibold text-gray-700">{{ $fleetData['reserved'] }}</div>
                        <div class="text-xs text-gray-600">Reserved</div>
                    </div>
                </div>

                {{-- Progress bar --}}
                @if($fleetData['total'] > 0)
                    <div class="mt-2">
                        <div class="flex h-2 rounded-full overflow-hidden bg-gray-100">
                            @if($fleetData['in_service_pct'] > 0)
                                <div class="bg-success-500" style="width: {{ $fleetData['in_service_pct'] }}%"></div>
                            @endif
                            @if($fleetData['out_of_service_pct'] > 0)
                                <div class="bg-danger-500" style="width: {{ $fleetData['out_of_service_pct'] }}%"></div>
                            @endif
                            @if($fleetData['maintenance_pct'] > 0)
                                <div class="bg-warning-500" style="width: {{ $fleetData['maintenance_pct'] }}%"></div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div class="text-center py-4">
                <x-heroicon-o-truck class="w-8 h-8 text-gray-400 mx-auto mb-2" />
                <p class="text-gray-500 text-sm">Loading fleet data...</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
