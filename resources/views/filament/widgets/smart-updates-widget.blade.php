<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            AI Smart Updates
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="sm">
                Refresh
            </x-filament::button>
        </x-slot>

        @if($isLoading)
            <div class="flex items-center justify-center p-6">
                <x-filament::loading-indicator class="h-8 w-8" />
                <span class="ml-3 text-gray-600">Loading...</span>
            </div>
        @elseif($smartUpdateData)
            <div class="space-y-4">
                {{-- Summary --}}
                @if(isset($smartUpdateData['summary_markdown']))
                    <div class="prose dark:prose-invert max-w-none">
                        {!! \Illuminate\Support\Str::markdown($smartUpdateData['summary_markdown']) !!}
                    </div>
                @endif

                {{-- Action Items --}}
                @if(!empty($smartUpdateData['action_items']))
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Action Items</h3>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach($smartUpdateData['action_items'] as $item)
                                <li class="text-gray-700 dark:text-gray-300">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Risks --}}
                @if(!empty($smartUpdateData['risks']))
                    <div>
                        <h3 class="text-lg font-semibold mb-2 text-red-600 dark:text-red-400">Risks</h3>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach($smartUpdateData['risks'] as $risk)
                                <li class="text-red-700 dark:text-red-300">{{ $risk }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Timestamp --}}
                @if(isset($smartUpdateData['generated_at']))
                    <div class="text-xs text-gray-500 dark:text-gray-400 pt-2 border-t">
                        Generated: {{ \Carbon\Carbon::parse($smartUpdateData['generated_at'])->diffForHumans() }}
                    </div>
                @endif

                {{-- Error Display --}}
                @if(isset($smartUpdateData['error']))
                    <div class="text-xs text-orange-600 dark:text-orange-400">
                        Note: {{ $smartUpdateData['error'] }}
                    </div>
                @endif
            </div>
        @else
            <div class="text-gray-500 p-4">
                No updates available. Click Refresh to load.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
