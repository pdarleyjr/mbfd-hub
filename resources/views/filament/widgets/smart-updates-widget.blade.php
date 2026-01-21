<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-bolt class="w-5 h-5 text-primary-500" />
                AI Smart Updates
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="sm" icon="heroicon-o-arrow-path">
                Refresh
            </x-filament::button>
        </x-slot>

        @if($isLoading)
            <div class="flex items-center justify-center p-4">
                <x-filament::loading-indicator class="h-6 w-6" />
                <span class="ml-2 text-sm text-gray-500">Loading...</span>
            </div>
        @elseif($smartUpdateData && isset($smartUpdateData['bullets']))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {{-- Vehicle Inventory --}}
                @if(!empty($smartUpdateData['bullets']['vehicle_inventory']))
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3">
                    <h4 class="text-xs font-semibold text-blue-700 dark:text-blue-300 uppercase tracking-wide mb-2 flex items-center gap-1">
                        <x-heroicon-o-truck class="w-4 h-4" />
                        Fleet Status
                    </h4>
                    <ul class="space-y-1">
                        @foreach($smartUpdateData['bullets']['vehicle_inventory'] as $item)
                            <li class="text-sm text-gray-700 dark:text-gray-300 flex items-start gap-1">
                                <span class="text-blue-500 mt-1">•</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Out of Service --}}
                @if(!empty($smartUpdateData['bullets']['out_of_service']))
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                    <h4 class="text-xs font-semibold text-red-700 dark:text-red-300 uppercase tracking-wide mb-2 flex items-center gap-1">
                        <x-heroicon-o-exclamation-triangle class="w-4 h-4" />
                        Out of Service
                    </h4>
                    <ul class="space-y-1">
                        @foreach($smartUpdateData['bullets']['out_of_service'] as $item)
                            <li class="text-sm text-gray-700 dark:text-gray-300 flex items-start gap-1">
                                <span class="text-red-500 mt-1">•</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Apparatus Issues --}}
                @if(!empty($smartUpdateData['bullets']['apparatus_issues']))
                <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-3">
                    <h4 class="text-xs font-semibold text-orange-700 dark:text-orange-300 uppercase tracking-wide mb-2 flex items-center gap-1">
                        <x-heroicon-o-wrench-screwdriver class="w-4 h-4" />
                        Apparatus Issues
                    </h4>
                    <ul class="space-y-1">
                        @foreach($smartUpdateData['bullets']['apparatus_issues'] as $item)
                            <li class="text-sm text-gray-700 dark:text-gray-300 flex items-start gap-1">
                                <span class="text-orange-500 mt-1">•</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Equipment Alerts --}}
                @if(!empty($smartUpdateData['bullets']['equipment_alerts']))
                <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-3">
                    <h4 class="text-xs font-semibold text-yellow-700 dark:text-yellow-300 uppercase tracking-wide mb-2 flex items-center gap-1">
                        <x-heroicon-o-cog-6-tooth class="w-4 h-4" />
                        Shop Work
                    </h4>
                    <ul class="space-y-1">
                        @foreach($smartUpdateData['bullets']['equipment_alerts'] as $item)
                            <li class="text-sm text-gray-700 dark:text-gray-300 flex items-start gap-1">
                                <span class="text-yellow-600 mt-1">•</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Capital Projects --}}
                @if(!empty($smartUpdateData['bullets']['capital_projects']))
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                    <h4 class="text-xs font-semibold text-green-700 dark:text-green-300 uppercase tracking-wide mb-2 flex items-center gap-1">
                        <x-heroicon-o-clipboard-document-list class="w-4 h-4" />
                        Capital Projects
                    </h4>
                    <ul class="space-y-1">
                        @foreach($smartUpdateData['bullets']['capital_projects'] as $item)
                            <li class="text-sm text-gray-700 dark:text-gray-300 flex items-start gap-1">
                                <span class="text-green-500 mt-1">•</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between mt-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                @if(isset($smartUpdateData['generated_at']))
                    <span class="text-xs text-gray-400">
                        Updated {{ \Carbon\Carbon::parse($smartUpdateData['generated_at'])->diffForHumans() }}
                    </span>
                @endif
                @if(isset($smartUpdateData['error']))
                    <span class="text-xs text-orange-500">
                        {{ $smartUpdateData['error'] }}
                    </span>
                @endif
            </div>
        @else
            <div class="text-gray-500 text-sm p-4 text-center">
                No updates available. Click Refresh to load.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
