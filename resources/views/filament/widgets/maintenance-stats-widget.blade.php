<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-wrench-screwdriver class="w-5 h-5 text-warning-500" />
                Maintenance & Repairs
            </div>
        </x-slot>

        @php
            $data = $this->getViewData();
        @endphp

        <div class="space-y-4">
            {{-- Summary Counts --}}
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg border border-yellow-200 dark:border-yellow-800">
                    <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300">{{ $data['pendingRecommendationsCount'] }}</div>
                    <div class="text-xs text-yellow-600 dark:text-yellow-400 font-medium">Pending Recs</div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                    <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $data['recentAllocationsCount'] }}</div>
                    <div class="text-xs text-blue-600 dark:text-blue-400 font-medium">Allocations (7d)</div>
                </div>

                <div class="bg-purple-50 dark:bg-purple-900/20 p-3 rounded-lg border border-purple-200 dark:border-purple-800">
                    <div class="text-2xl font-bold text-purple-700 dark:text-purple-300">{{ $data['activeShopWorkCount'] }}</div>
                    <div class="text-xs text-purple-600 dark:text-purple-400 font-medium">Active Work</div>
                </div>
            </div>

            {{-- Pending Recommendations --}}
            @if(count($data['pendingRecommendations']) > 0)
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg">
                    <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-700 rounded-t-lg">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Pending Replacement Recommendations</h4>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 dashboard-widget-compact">
                        @foreach($data['pendingRecommendations'] as $rec)
                            <li class="px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ $rec['unit'] }}: {{ $rec['defect'] }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Recommended: {{ $rec['recommended'] }}
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                        @php
                                            $confidence = $rec['confidence'] * 100;
                                        @endphp
                                        <span class="text-xs font-semibold px-2 py-1 rounded-full 
                                            {{ $confidence >= 80 ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' : 
                                               ($confidence >= 50 ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' : 
                                               'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300') }}">
                                            {{ number_format($confidence, 0) }}%
                                        </span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 rounded-b-lg">
                        <a href="{{ route('filament.admin.resources.recommendations.index') }}" 
                           class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium">
                            View all recommendations →
                        </a>
                    </div>
                </div>
            @endif

            {{-- Recent Allocations --}}
            @if(count($data['recentAllocations']) > 0)
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg">
                    <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-700 rounded-t-lg">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Recent Parts Allocations</h4>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 dashboard-widget-compact">
                        @foreach($data['recentAllocations'] as $alloc)
                            <li class="px-3 py-2 flex items-center justify-between gap-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <div class="flex-1 min-w-0">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $alloc['unit'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">• {{ $alloc['item'] }}</span>
                                </div>
                                <div class="flex-shrink-0 text-right">
                                    <div class="text-sm font-semibold text-blue-700 dark:text-blue-300">×{{ $alloc['qty'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($alloc['date'])->diffForHumans() }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 rounded-b-lg">
                        <a href="{{ route('filament.admin.resources.apparatuses.index') }}" 
                           class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium">
                            View maintenance logs →
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
