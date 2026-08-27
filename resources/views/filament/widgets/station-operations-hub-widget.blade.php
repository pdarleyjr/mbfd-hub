<x-filament-widgets::widget>
    <div x-data="{ activeTab: 0 }">
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-building-office-2 class="w-5 h-5 text-primary-600" />
                    Station Operations Hub
                </div>
            </x-slot>

            @if(count($stations) > 0)
                {{-- Tab Bar --}}
                <div class="overflow-x-auto -mx-1 px-1">
                    <x-filament::tabs label="Station tabs">
                        @foreach($stations as $index => $station)
                            <x-filament::tabs.item
                                :alpine-active="'activeTab === ' . $index"
                                x-on:click="activeTab = {{ $index }}"
                            >
                                Station {{ $station['station_number'] }}
                            </x-filament::tabs.item>
                        @endforeach
                    </x-filament::tabs>
                </div>

                {{-- Tab Panels --}}
                @foreach($stations as $index => $station)
                    @php
                        $data = $stationData[$station['id']] ?? [];
                        $counts = $data['counts'] ?? [];
                    @endphp
                    <div x-show="activeTab === {{ $index }}" x-cloak class="mt-4 space-y-4">

                        {{-- Canonical Daily Checkout — never count raw inspection rows here. --}}
                        @include('filament.widgets.partials.station-hub-section', [
                            'title' => 'Daily Checkout Compliance',
                            'subtitle' => $data['dailyCheckoutSubtitle'] ?? null,
                            'icon' => 'heroicon-o-clipboard-document-check',
                            'count' => $counts['dailyCheckoutCompleted'] ?? 0,
                            'items' => $data['dailyCheckoutRows'] ?? [],
                            'emptyMessage' => 'No Daily Checkout policy rows',
                            'columns' => ['unit' => 'Unit', 'state' => 'Daily state', 'completion' => 'Completion'],
                            'statusField' => 'state',
                            'dangerStatuses' => ['not_checked', 'classification_required'],
                        ])

                        {{-- Station Inspections (30 days) --}}
                        @include('filament.widgets.partials.station-hub-section', [
                            'title' => 'Station Inspections',
                            'subtitle' => 'Last 30 days',
                            'icon' => 'heroicon-o-building-office',
                            'count' => $counts['stationInspections'] ?? 0,
                            'items' => $data['stationInspections'] ?? [],
                            'emptyMessage' => 'No station inspections in the last 30 days',
                            'columns' => ['date' => 'Date', 'type' => 'Type', 'inspector' => 'Inspector', 'status' => 'Status'],
                            'statusField' => 'status',
                        ])

                        {{-- Canonical Station Requests --}}
                        @include('filament.widgets.partials.station-hub-section', [
                            'title' => 'Station Requests',
                            'icon' => 'heroicon-o-wrench-screwdriver',
                            'count' => $counts['stationRequests'] ?? 0,
                            'items' => $data['stationRequests'] ?? [],
                            'emptyMessage' => 'No station requests',
                            'columns' => ['number' => 'Request', 'type' => 'Type', 'title' => 'Subject', 'requested_by' => 'Requested By', 'priority' => 'Priority', 'status' => 'Status'],
                            'statusField' => 'status',
                            'priorityField' => 'priority',
                        ])

                        {{-- Apparatus Defects --}}
                        @include('filament.widgets.partials.station-hub-section', [
                            'title' => 'Unresolved Defects',
                            'icon' => 'heroicon-o-exclamation-triangle',
                            'count' => $counts['defects'] ?? 0,
                            'items' => $data['defects'] ?? [],
                            'emptyMessage' => 'No unresolved defects',
                            'columns' => ['unit' => 'Unit', 'item' => 'Item', 'status' => 'Status', 'reported_date' => 'Reported'],
                            'statusField' => 'status',
                            'dangerStatuses' => ['Missing', 'Damaged'],
                        ])

                        {{-- Station Supply Requests --}}
                        @include('filament.widgets.partials.station-hub-section', [
                            'title' => 'Open Supply Requests',
                            'icon' => 'heroicon-o-inbox-stack',
                            'count' => $counts['supplyRequests'] ?? 0,
                            'items' => $data['supplyRequests'] ?? [],
                            'emptyMessage' => 'No open supply requests',
                            'columns' => ['request_text' => 'Request', 'created_by' => 'Requested By', 'shift' => 'Shift', 'date' => 'Date'],
                        ])

                    </div>
                @endforeach
            @else
                <div class="text-center py-8">
                    <x-heroicon-o-building-office-2 class="w-12 h-12 mx-auto text-gray-300" />
                    <p class="mt-2 text-sm text-gray-500">No active stations found</p>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
