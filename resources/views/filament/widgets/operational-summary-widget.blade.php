<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-truck class="w-5 h-5 text-primary-500" />
                Operational Summary
            </div>
        </x-slot>

        @php
            $data = $this->getViewData();
        @endphp

        <div class="space-y-4">
            {{-- Stats Grid --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                    <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $data['totalApparatus'] }}</div>
                    <div class="text-xs text-blue-600 dark:text-blue-400 font-medium">Total Apparatus</div>
                </div>

                <div class="bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-200 dark:border-green-800">
                    <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ $data['inService'] }}</div>
                    <div class="text-xs text-green-600 dark:text-green-400 font-medium">In Service</div>
                </div>

                <div class="bg-red-50 dark:bg-red-900/20 p-3 rounded-lg border border-red-200 dark:border-red-800">
                    <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ $data['outOfService'] }}</div>
                    <div class="text-xs text-red-600 dark:text-red-400 font-medium">Out of Service</div>
                </div>

                <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg border border-yellow-200 dark:border-yellow-800">
                    <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300">{{ $data['overdueInspections'] }}</div>
                    <div class="text-xs text-yellow-600 dark:text-yellow-400 font-medium">Overdue Inspections</div>
                </div>
            </div>

            {{-- Defects Summary --}}
            <div class="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg border border-orange-200 dark:border-orange-800">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-wrench-screwdriver class="w-5 h-5 text-orange-600" />
                    <div>
                        <div class="text-sm font-semibold text-orange-700 dark:text-orange-300">
                            {{ $data['openDefects'] }} Open Defects
                        </div>
                        <div class="text-xs text-orange-600 dark:text-orange-400">
                            {{ $data['criticalDefects'] }} critical
                        </div>
                    </div>
                </div>
                <a href="{{ route('filament.admin.resources.defects.index') }}" 
                   class="text-xs text-orange-600 dark:text-orange-400 hover:text-orange-700 dark:hover:text-orange-300 font-medium">
                    View all →
                </a>
            </div>

            {{-- Urgent Alerts --}}
            @if(count($data['urgentAlerts']) > 0)
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg">
                    <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-700 rounded-t-lg">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Urgent Alerts</h4>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 dashboard-widget-compact">
                        @foreach($data['urgentAlerts'] as $alert)
                            <li class="px-3 py-2 flex items-start gap-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                @if($alert['type'] === 'critical')
                                    <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-red-600 mt-0.5 flex-shrink-0" />
                                    <span class="text-red-700 dark:text-red-300">{{ $alert['message'] }}</span>
                                @else
                                    <x-heroicon-o-wrench-screwdriver class="w-4 h-4 text-yellow-600 mt-0.5 flex-shrink-0" />
                                    <span class="text-yellow-700 dark:text-yellow-300">{{ $alert['message'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 rounded-b-lg">
                        <a href="{{ route('filament.admin.resources.defects.index') }}" 
                           class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium">
                            View all alerts →
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
