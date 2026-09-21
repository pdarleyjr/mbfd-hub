<x-filament-widgets::widget>
    @php
        $apparatusUrl = \App\Filament\Resources\ApparatusResource::getUrl('index');
        $stationsUrl = \App\Filament\Resources\StationResource::getUrl('index');
        $requestsUrl = \App\Filament\Resources\StationRequestResource::getUrl('index');
        $inventoryUrl = \App\Filament\Resources\EquipmentItemResource::getUrl('index');
        $isFocused = $selectedStationId !== 'all';
    @endphp

    <div class="mbfd-station-console" wire:key="station-operations-{{ $selectedStationId }}">
        <x-filament::section>
            <x-slot name="heading">Station Operations</x-slot>
            <x-slot name="description">Operational readiness, current work, and the next record to open. Refreshes every 20 seconds.</x-slot>

            <div class="mbfd-station-console-toolbar">
                <label class="mbfd-station-console-selector" for="station-operations-selector">
                    <span>Workspace</span>
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
                <a href="{{ $stationsUrl }}"><strong>Readiness</strong><span>{{ $department['readinessPercent'] }}% across operational stations</span></a>
                <a href="{{ $apparatusUrl }}"><strong>Fleet</strong><span>{{ $department['apparatus']['in_service'] }} in service · {{ $department['apparatus']['out_of_service'] }} OOS · {{ $department['apparatus']['maintenance'] }} maintenance</span></a>
                <a href="{{ $requestsUrl }}"><strong>Daily / Work</strong><span>{{ $department['daily']['completed'] }} / {{ $department['daily']['required'] }} Daily · {{ $department['work']['requests'] }} requests · {{ $department['work']['missing'] }} missing</span></a>
                <a href="{{ $inventoryUrl }}"><strong>Inventory</strong><span>{{ $department['lowStock'] }} low-stock exceptions · {{ $department['work']['supplies'] }} open supply requests</span></a>
            </div>
        </x-filament::section>

        @if ($isFocused)
            @forelse($stations as $station)
                @php
                    $data = $stationData[$station['id']] ?? [];
                    $daily = $data['dailyCheckout'] ?? [];
                    $counts = $data['counts'] ?? [];
                    $apparatus = $data['apparatus'] ?? [];
                    $readiness = $data['readiness'] ?? [];
                @endphp
                <section class="mbfd-station-console-focus" aria-label="Station {{ $station['station_number'] }} operational workspace">
                    <header class="mbfd-station-console-focus-header">
                        <div>
                            <p class="mbfd-station-console-kicker">Focused station workspace</p>
                            <h2>Station {{ $station['station_number'] }}</h2>
                            <p>{{ $readiness['percent'] ?? 0 }}% readiness · {{ str($readiness['status'] ?? 'unknown')->headline() }}</p>
                        </div>
                        <a class="mbfd-station-console-open" href="{{ $data['stationUrl'] }}">Full Station Profile</a>
                    </header>

                    <div class="mbfd-station-console-focus-grid">
                        <section class="mbfd-station-console-panel">
                            <h3>Apparatus state</h3>
                            <p class="mbfd-station-console-summary">{{ $apparatus['in_service'] ?? 0 }} in service · {{ $apparatus['out_of_service'] ?? 0 }} OOS · {{ $apparatus['maintenance'] ?? 0 }} maintenance</p>
                            <ul class="mbfd-station-console-link-list">
                                @forelse($apparatus['records'] ?? [] as $record)
                                    <li><a href="{{ $record['url'] }}">{{ $record['label'] }} <span>{{ str($record['status'])->headline() }}</span></a></li>
                                @empty
                                    <li>No apparatus assigned.</li>
                                @endforelse
                            </ul>
                        </section>

                        <section class="mbfd-station-console-panel">
                            <h3>Daily Checkout</h3>
                            <p class="mbfd-station-console-summary">{{ $daily['completed'] ?? 0 }} / {{ $daily['required_total'] ?? 0 }} complete · {{ $daily['attention'] ?? 0 }} attention · {{ $daily['review_pending'] ?? 0 }} review</p>
                            <ul class="mbfd-station-console-matrix">
                                @forelse($daily['matrix'] ?? [] as $entry)
                                    <li><span>{{ $entry['designation'] ?? $entry['unit_id'] ?? 'Apparatus' }}</span><strong>{{ str($entry['state'] ?? 'unknown')->headline() }}</strong></li>
                                @empty
                                    <li>No Daily Checkout matrix is available.</li>
                                @endforelse
                            </ul>
                        </section>

                        <section class="mbfd-station-console-panel">
                            <h3>Open work</h3>
                            <div class="mbfd-station-console-exceptions">
                                <a href="{{ $data['links']['requests'] }}">Requests <strong>{{ $counts['stationRequests'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['tickets'] }}">Service tickets <strong>{{ $counts['serviceTickets'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['defects'] }}">Defects <strong>{{ $counts['defects'] ?? 0 }}</strong> / missing <strong>{{ $counts['missingDefects'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['supplies'] }}">Supply requests <strong>{{ $counts['supplyRequests'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['inventorySubmissions'] }}">Inventory submissions</a>
                            </div>
                            @if(! empty($readiness['reasons']))
                                <p class="mbfd-station-console-reasons">{{ collect($readiness['reasons'])->take(2)->implode(' · ') }}</p>
                            @endif
                        </section>
                    </div>

                    <section class="mbfd-station-console-panel mbfd-station-console-activity">
                        <h3>Recent submissions and activity</h3>
                        <ol>
                            @forelse($data['recentActivity'] ?? [] as $item)
                                <li><a href="{{ $item['url'] }}"><strong>{{ $item['label'] }}</strong><span>{{ str($item['type'])->headline() }} · {{ str($item['status'])->headline() }} · {{ $item['timestamp'] }}</span></a></li>
                            @empty
                                <li>No recent operational record.</li>
                            @endforelse
                        </ol>
                    </section>
                </section>
            @empty
                <x-filament::section>No operational station matches this focus.</x-filament::section>
            @endforelse
        @else
            <div class="mbfd-station-console-grid">
                @forelse($stations as $station)
                    @php
                        $data = $stationData[$station['id']] ?? [];
                        $daily = $data['dailyCheckout'] ?? [];
                        $counts = $data['counts'] ?? [];
                        $apparatus = $data['apparatus'] ?? [];
                        $readiness = $data['readiness'] ?? [];
                        $hasAttention = ($readiness['status'] ?? 'READY') !== 'READY';
                    @endphp
                    <article class="mbfd-station-console-card {{ $hasAttention ? 'mbfd-station-console-card-attention' : '' }}">
                        <div class="mbfd-station-console-card-header">
                            <div><p class="mbfd-station-console-kicker">Station {{ $station['station_number'] }}</p><h3>{{ $readiness['percent'] ?? 0 }}% {{ str($readiness['status'] ?? 'unknown')->headline() }}</h3></div>
                            <a class="mbfd-station-console-open" href="{{ $data['stationUrl'] }}">Profile</a>
                        </div>
                        <p class="mbfd-station-console-summary">Daily {{ $daily['completed'] ?? 0 }} / {{ $daily['required_total'] ?? 0 }} · Fleet {{ $apparatus['in_service'] ?? 0 }} in service / {{ $apparatus['out_of_service'] ?? 0 }} OOS</p>
                        <div class="mbfd-station-console-exceptions">
                            <a href="{{ $data['links']['requests'] }}">Requests <strong>{{ $counts['stationRequests'] ?? 0 }}</strong></a>
                            <a href="{{ $data['links']['tickets'] }}">Service <strong>{{ $counts['serviceTickets'] ?? 0 }}</strong></a>
                            <a href="{{ $data['links']['defects'] }}">Defects <strong>{{ $counts['defects'] ?? 0 }}</strong> / missing <strong>{{ $counts['missingDefects'] ?? 0 }}</strong></a>
                        </div>
                        <div class="mbfd-station-console-recent">
                            @forelse($data['recentInspections'] ?? [] as $inspection)
                                <a href="{{ $inspection['url'] }}">{{ $inspection['label'] }} <span>{{ str($inspection['status'])->headline() }}</span></a>
                            @empty
                                <span>No station inspection record</span>
                            @endforelse
                        </div>
                    </article>
                @empty
                    <x-filament::section>No operational stations are configured.</x-filament::section>
                @endforelse
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
