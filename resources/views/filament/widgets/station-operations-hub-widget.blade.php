<x-filament-widgets::widget>
    <div>
        <x-filament::section class="mbfd-station-overview">
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-building-office-2 class="w-5 h-5 text-primary-600" />
                    Station readiness
                </div>
            </x-slot>

            <x-slot name="description">
                Open a station or exception for the detailed operational record.
            </x-slot>

            @if(count($stations) > 0)
                <div class="mbfd-station-overview-grid">
                    @foreach($stations as $station)
                    @php
                        $data = $stationData[$station['id']] ?? [];
                        $counts = $data['counts'] ?? [];
                        $daily = $data['dailyCheckout'] ?? [];
                        $dailyTotal = (int) ($daily['required_total'] ?? 0);
                        $dailyCompleted = (int) ($counts['dailyCheckoutCompleted'] ?? 0);
                        $attention = (int) ($counts['defects'] ?? 0) + (int) ($counts['stationRequests'] ?? 0) + (int) ($counts['supplyRequests'] ?? 0);
                    @endphp
                        <a
                            class="mbfd-station-overview-card {{ $attention > 0 ? 'mbfd-station-overview-card-attention' : '' }}"
                            href="{{ \App\Filament\Resources\StationResource::getUrl('view', ['record' => $station['id']]) }}"
                            aria-label="Open Station {{ $station['station_number'] }} operational details"
                        >
                            <span class="mbfd-station-overview-name">Station {{ $station['station_number'] }}</span>
                            <span class="mbfd-station-overview-checkout">Daily checkout {{ $dailyTotal > 0 ? $dailyCompleted.'/'.$dailyTotal : 'not required' }}</span>
                            <span class="mbfd-station-overview-metrics">
                                <span>Requests <strong>{{ $counts['stationRequests'] ?? 0 }}</strong></span>
                                <span>Defects <strong>{{ $counts['defects'] ?? 0 }}</strong></span>
                                <span>Supply <strong>{{ $counts['supplyRequests'] ?? 0 }}</strong></span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <x-heroicon-o-building-office-2 class="w-12 h-12 mx-auto text-gray-300" />
                    <p class="mt-2 text-sm text-gray-500">No active stations found</p>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
