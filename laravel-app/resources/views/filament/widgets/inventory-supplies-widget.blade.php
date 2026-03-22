<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-cube class="w-5 h-5 text-info-500" />
                Inventory & Supplies
            </div>
        </x-slot>

        @php
            $data = $this->getViewData();
        @endphp

        <div class="space-y-4">
            {{-- Stats Grid --}}
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                    <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $data['totalItems'] }}</div>
                    <div class="text-xs text-blue-600 dark:text-blue-400 font-medium">Total Items</div>
                </div>

                <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg border border-yellow-200 dark:border-yellow-800">
                    <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300">{{ $data['lowStockCount'] }}</div>
                    <div class="text-xs text-yellow-600 dark:text-yellow-400 font-medium">Low Stock</div>
                </div>

                <div class="bg-red-50 dark:bg-red-900/20 p-3 rounded-lg border border-red-200 dark:border-red-800">
                    <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ $data['outOfStockCount'] }}</div>
                    <div class="text-xs text-red-600 dark:text-red-400 font-medium">Out of Stock</div>
                </div>
            </div>

            {{-- Top 5 Low Stock Items --}}
            @if(count($data['topLowStockItems']) > 0)
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg">
                    <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-700 rounded-t-lg">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Top 5 Low Stock Items</h4>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 dashboard-widget-compact">
                        @foreach($data['topLowStockItems'] as $item)
                            <li class="px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            @if($item['status'] === 'out')
                                                <span class="flex-shrink-0 w-2 h-2 bg-red-500 rounded-full"></span>
                                                <span class="text-sm font-medium text-red-700 dark:text-red-300 truncate">
                                                    {{ $item['name'] }}
                                                </span>
                                            @else
                                                <span class="flex-shrink-0 w-2 h-2 bg-yellow-500 rounded-full"></span>
                                                <span class="text-sm font-medium text-yellow-700 dark:text-yellow-300 truncate">
                                                    {{ $item['name'] }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            {{ $item['category'] }} • {{ $item['location'] }}
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 text-right">
                                        <div class="text-sm font-semibold {{ $item['status'] === 'out' ? 'text-red-700 dark:text-red-300' : 'text-yellow-700 dark:text-yellow-300' }}">
                                            {{ $item['stock'] }}/{{ $item['reorder_min'] }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $item['status'] === 'out' ? 'Out' : 'Low' }}
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 rounded-b-lg">
                        <a href="{{ route('filament.admin.resources.equipment-items.index') }}" 
                           class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium">
                            View all inventory →
                        </a>
                    </div>
                </div>
            @else
                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border border-green-200 dark:border-green-800 text-center">
                    <x-heroicon-o-check-circle class="w-8 h-8 text-green-600 mx-auto mb-2" />
                    <p class="text-sm text-green-700 dark:text-green-300 font-medium">All items adequately stocked</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
