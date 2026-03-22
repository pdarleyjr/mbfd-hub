<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-bolt class="w-5 h-5 text-primary-500" />
                Command Center
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="sm" icon="heroicon-o-arrow-path">
                Refresh
            </x-filament::button>
        </x-slot>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Left: Instant Bullet Summary (2 cols) --}}
            <div class="lg:col-span-2 space-y-3">
                @if($bulletSummary)
                    @foreach($bulletSummary as $key => $section)
                        @php
                            $colors = [
                                'red' => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
                                'orange' => 'bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-800',
                                'yellow' => 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
                                'green' => 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800',
                                'blue' => 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
                                'purple' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
                            ];
                            $colorClass = $colors[$section['color']] ?? $colors['blue'];
                        @endphp
                        <div class="rounded-lg p-3 border {{ $colorClass }}">
                            <h4 class="text-xs font-semibold uppercase tracking-wide mb-2 flex items-center gap-2">
                                <span>{{ $section['icon'] }}</span>
                                {{ $section['title'] }}
                                <span class="ml-auto text-xs font-normal opacity-70">{{ count($section['items']) }}</span>
                            </h4>
                            <ul class="space-y-1">
                                @foreach($section['items'] as $item)
                                    <li class="text-sm flex items-start gap-1">
                                        <span class="mt-0.5">•</span>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @else
                    <div class="text-gray-500 text-sm p-4 text-center">
                        Loading summary...
                    </div>
                @endif
            </div>

            {{-- Right: Always-Visible AI Chat (1 col) --}}
            <div class="lg:col-span-1">
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 h-full flex flex-col">
                    {{-- Chat Header --}}
                    <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-primary-50 dark:bg-primary-900/20 rounded-t-lg">
                        <h4 class="text-sm font-semibold text-primary-700 dark:text-primary-300 flex items-center gap-2">
                            <x-heroicon-o-chat-bubble-left-right class="w-4 h-4" />
                            AI Assistant
                        </h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Ask questions or request changes</p>
                    </div>

                    {{-- Chat Messages --}}
                    <div class="flex-1 overflow-y-auto p-3 space-y-2 min-h-[200px] max-h-[300px]">
                        @if(empty($chatMessages))
                            <div class="text-xs text-gray-400 text-center py-4">
                                <p>Ask me anything about:</p>
                                <ul class="mt-1 space-y-0.5">
                                    <li>• Inventory status</li>
                                    <li>• Fleet updates</li>
                                    <li>• Project status</li>
                                    <li>• Make changes</li>
                                </ul>
                            </div>
                        @else
                            @foreach($chatMessages as $msg)
                                <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                                    <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm {{ $msg['role'] === 'user' ? 'bg-primary-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-600' }}">
                                        <p class="whitespace-pre-wrap">{{ $msg['content'] }}</p>
                                        <span class="text-[10px] opacity-60 block mt-1">{{ $msg['time'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                        
                        @if($chatLoading)
                            <div class="flex justify-start">
                                <div class="bg-white dark:bg-gray-700 rounded-lg px-3 py-2 border border-gray-200 dark:border-gray-600">
                                    <x-filament::loading-indicator class="h-4 w-4" />
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Chat Input --}}
                    <div class="p-2 border-t border-gray-200 dark:border-gray-700">
                        <form wire:submit="sendChat" class="flex gap-2">
                            <input
                                type="text"
                                wire:model="chatInput"
                                placeholder="Type a message..."
                                class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500"
                                @if($chatLoading) disabled @endif
                            >
                            <x-filament::button type="submit" size="sm" :disabled="$chatLoading">
                                <x-heroicon-o-paper-airplane class="w-4 h-4" />
                            </x-filament::button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
