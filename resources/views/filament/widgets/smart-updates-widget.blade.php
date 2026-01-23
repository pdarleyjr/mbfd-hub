<x-filament-widgets::widget>
    <x-filament::section 
        x-data="{ expanded: @entangle('isExpanded') }">
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-chat-bubble-left-right class="w-5 h-5 text-primary-500" />
                AI Assistant
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <button 
                @click="expanded = !expanded"
                class="text-sm text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium flex items-center gap-1">
                <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                <x-heroicon-o-chevron-down 
                    class="w-4 h-4 transition-transform duration-200"
                    x-bind:class="expanded ? 'rotate-180' : ''" />
            </button>
        </x-slot>

        <div>
            {{-- Collapsed State - Compact Summary --}}
            <div x-show="!expanded" class="space-y-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Ask the AI assistant about inventory, fleet status, projects, or request changes.
                </p>
                <x-filament::button 
                    @click="expanded = true"
                    size="sm" 
                    color="primary">
                    <x-heroicon-o-sparkles class="w-4 h-4 mr-1" />
                    Ask AI
                </x-filament::button>
            </div>

            {{-- Expanded State - Full Chat Interface --}}
            <div x-show="expanded" x-cloak class="space-y-3">
                {{-- Chat Messages --}}
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-3 min-h-[200px] max-h-[400px] overflow-y-auto dashboard-widget-scrollable">
                    @if(empty($chatMessages))
                        <div class="text-xs text-gray-400 text-center py-4">
                            <p class="font-medium mb-2">Ask me anything about:</p>
                            <ul class="space-y-1">
                                <li>• Inventory status and low stock items</li>
                                <li>• Fleet updates and defects</li>
                                <li>• Project status and milestones</li>
                                <li>• Make changes to records</li>
                            </ul>
                        </div>
                    @else
                        @foreach($chatMessages as $msg)
                            <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }} mb-2">
                                <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm {{ $msg['role'] === 'user' ? 'bg-primary-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-600' }}">
                                    <p class="whitespace-pre-wrap">{{ $msg['content'] }}</p>
                                    <span class="text-[10px] opacity-60 block mt-1">{{ $msg['time'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                    
                    @if($chatLoading)
                        <div class="flex justify-start mb-2">
                            <div class="bg-white dark:bg-gray-700 rounded-lg px-3 py-2 border border-gray-200 dark:border-gray-600">
                                <x-filament::loading-indicator class="h-4 w-4" />
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Chat Input --}}
                <form wire:submit="sendChat" class="flex gap-2">
                    <input
                        type="text"
                        wire:model="chatInput"
                        placeholder="Type your question or request..."
                        class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500"
                        @if($chatLoading) disabled @endif
                    >
                    <x-filament::button type="submit" size="sm" :disabled="$chatLoading">
                        <x-heroicon-o-paper-airplane class="w-4 h-4" />
                    </x-filament::button>
                </form>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
