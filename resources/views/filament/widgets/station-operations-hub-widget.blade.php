<x-filament-widgets::widget>
    @php
        $apparatusUrl = \App\Filament\Resources\ApparatusResource::getUrl('index');
        $stationsUrl = \App\Filament\Resources\StationResource::getUrl('index');
        $defectsUrl = \App\Filament\Resources\DefectResource::getUrl('index');
        $requestsUrl = \App\Filament\Resources\StationRequestResource::getUrl('index');
        $ticketsUrl = \App\Filament\Resources\ApparatusServiceTicketResource::getUrl('index');
        $inventoryUrl = \App\Filament\Resources\EquipmentItemResource::getUrl('index');
    @endphp

    <div class="mbfd-station-console" wire:key="station-operations-{{ $selectedStationId }}">
        <x-filament::section>
            <x-slot name="heading">Station Operations</x-slot>
            <x-slot name="description">Department readiness and station-level exceptions. Refreshes every 20 seconds.</x-slot>

            <div class="mbfd-station-console-toolbar">
                <label class="mbfd-station-console-selector" for="station-operations-selector">
                    <span>Focus</span>
                    <select id="station-operations-selector" wire:model.live="selectedStationId">
                        <option value="all">All stations</option>
                        @foreach($stationOptions as $option)
                            <option value="{{ $option['id'] }}">Station {{ $option['station_number'] }}</option>
                        @endforeach
                    </select>
                </label>
                <span class="mbfd-station-console-refresh" wire:loading>Refreshing operational data…</span>
            </div>

            <div class="mbfd-station-console-strip" aria-label="Department status">
                <a href="{{ $apparatusUrl }}"><strong>Fleet</strong><span>{{ $department['apparatus']['in_service'] }} in service · {{ $department['apparatus']['out_of_service'] }} OOS · {{ $department['apparatus']['maintenance'] }} maintenance</span></a>
                <a href="{{ $stationsUrl }}"><strong>Daily</strong><span>{{ $department['daily']['completed'] }} / {{ $department['daily']['required'] }} complete · {{ $department['daily']['attention'] }} attention · {{ $department['daily']['review_pending'] }} review · {{ $department['daily']['not_checked'] }} not checked</span></a>
                <a href="{{ $requestsUrl }}"><strong>Station work</strong><span>{{ $department['work']['requests'] }} requests · {{ $department['work']['service_tickets'] }} service · {{ $department['work']['defects'] }} defects · {{ $department['work']['missing'] }} missing</span></a>
                <a href="{{ $inventoryUrl }}"><strong>Inventory</strong><span>{{ $department['lowStock'] }} low-stock exceptions</span></a>
            </div>
        </x-filament::section>

        <div class="mbfd-station-console-grid">
            @forelse($stations as $station)
                @php
                    $data = $stationData[$station['id']] ?? [];
                    $daily = $data['dailyCheckout'] ?? [];
                    $counts = $data['counts'] ?? [];
                    $apparatus = $data['apparatus'] ?? [];
                    $hasAttention = (($counts['stationRequests'] ?? 0) + ($counts['serviceTickets'] ?? 0) + ($counts['defects'] ?? 0) + ($counts['supplyRequests'] ?? 0)) > 0;
                    $readiness = $data['readinessPercent'];
                @endphp
                <article class="mbfd-station-console-card {{ $hasAttention ? 'mbfd-station-console-card-attention' : '' }}">
                    <div class="mbfd-station-console-card-header">
                        <div>
                            <p class="mbfd-station-console-kicker">Station {{ $station['station_number'] }}</p>
                            <h3>{{ $readiness === null ? 'Readiness unavailable' : $readiness.'% Daily Checkout' }}</h3>
                        </div>
                        <a class="mbfd-station-console-open" href="{{ $data['stationUrl'] }}">Open</a>
                    </div>

                    <dl class="mbfd-station-console-metrics">
                        <div><dt>Apparatus</dt><dd>{{ $apparatus['total'] ?? 0 }}</dd></div>
                        <div><dt>In service</dt><dd>{{ $apparatus['in_service'] ?? 0 }}</dd></div>
                        <div><dt>OOS / maint.</dt><dd>{{ $apparatus['out_of_service'] ?? 0 }} / {{ $apparatus['maintenance'] ?? 0 }}</dd></div>
                        <div><dt>Daily</dt><dd>{{ $daily['completed'] ?? 0 }} / {{ $daily['required_total'] ?? 0 }}</dd></div>
                    </dl>

                    <div class="mbfd-station-console-exceptions">
                        <a href="{{ $requestsUrl }}">Requests <strong>{{ $counts['stationRequests'] ?? 0 }}</strong></a>
                        <a href="{{ $ticketsUrl }}">Service <strong>{{ $counts['serviceTickets'] ?? 0 }}</strong></a>
                        <a href="{{ $defectsUrl }}">Defects <strong>{{ $counts['defects'] ?? 0 }}</strong> / missing <strong>{{ $counts['missingDefects'] ?? 0 }}</strong></a>
                        <a href="{{ $data['stationUrl'] }}?activeRelationManager=supplyRequests">Supplies <strong>{{ $counts['supplyRequests'] ?? 0 }}</strong></a>
                    </div>

                    <div class="mbfd-station-console-recent">
                        <p>Recent inspection</p>
                        @forelse($data['recentInspections'] as $inspection)
                            <span>{{ $inspection['date'] ?? 'Undated' }} · {{ $inspection['status'] }}</span>
                        @empty
                            <span>No station inspection record</span>
                        @endforelse
                    </div>

                    @if(count($data['recentWork']) > 0)
                        <ul class="mbfd-station-console-work" aria-label="Recent station work">
                            @foreach($data['recentWork'] as $work)
                                <li>{{ $work['label'] }} <span>{{ str($work['type'])->headline() }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @empty
                <x-filament::section>
                    No active station matches this focus.
                </x-filament::section>
            @endforelse
        </div>
    </div>
</x-filament-widgets::widget>
