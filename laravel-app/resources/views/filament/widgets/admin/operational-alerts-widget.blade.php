<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-bell-alert class="w-5 h-5 text-danger-500" />
                Operational Alerts
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="xs" icon="heroicon-o-arrow-path" outlined>
                Refresh
            </x-filament::button>
        </x-slot>

        @if($alerts && count($alerts) > 0)
            <div class="space-y-2 max-h-[300px] overflow-y-auto">
                @foreach($alerts as $alert)
                    @php
                        $colorClasses = match($alert['type']) {
                            'danger' => 'bg-danger-50 border-danger-200 text-danger-700',
                            'warning' => 'bg-warning-50 border-warning-200 text-warning-700',
                            default => 'bg-gray-50 border-gray-200 text-gray-700',
                        };
                        $iconColor = match($alert['type']) {
                            'danger' => 'text-danger-500',
                            'warning' => 'text-warning-500',
                            default => 'text-gray-500',
                        };
                    @endphp
                    <div class="rounded-lg p-3 border {{ $colorClasses }}">
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 mt-0.5 {{ $iconColor }}" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-sm">{{ $alert['title'] }}</span>
                                    <span class="text-xs opacity-70 whitespace-nowrap">{{ $alert['time'] }}</span>
                                </div>
                                <p class="text-sm mt-0.5 truncate">{{ $alert['message'] }}</p>
                                @if($alert['details'])
                                    <p class="text-xs opacity-70 mt-1 truncate">{{ $alert['details'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-6">
                <x-heroicon-o-check-circle class="w-10 h-10 text-success-500 mx-auto mb-2" />
                <p class="text-gray-500 text-sm">No operational alerts</p>
                <p class="text-gray-400 text-xs mt-1">All systems operating normally</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
