<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-sparkles class="w-5 h-5 text-primary-500" />
                AI Assistant
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            @if(count($chatMessages) > 0)
                <x-filament::button wire:click="clearChat" size="xs" icon="heroicon-o-trash" outlined>
                    Clear
                </x-filament::button>
            @endif
        </x-slot>

        @if($aiEnabled)
            {{-- Quick prompts --}}
            <div class="mb-3">
                <p class="text-xs text-gray-500 mb-2">Quick prompts:</p>
                <div class="flex flex-wrap gap-1">
                    @foreach(\App\Filament\Widgets\Admin\AiAssistantWidget::getPresets() as $preset)
                        <x-filament::button 
                            wire:click="quickPrompt('{{ $preset['prompt'] }}')" 
                            size="xs" 
                            outlined
                            class="text-xs"
                        >
                            {{ $preset['label'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>

            {{-- Chat messages --}}
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 max-h-[200px] overflow-y-auto mb-2">
                <div class="p-2 space-y-2 min-h-[80px]">
                    @if(empty($chatMessages))
                        <div class="text-xs text-gray-400 text-center py-2">
                            <p>Ask a question or use a quick prompt above</p>
                        </div>
                    @else
                        @foreach($chatMessages as $msg)
                            <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[90%] rounded-lg px-2 py-1 text-xs {{ $msg['role'] === 'user' ? 'bg-primary-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-600' }}">
                                    <p class="whitespace-pre-wrap">{{ $msg['content'] }}</p>
                                </div>
                            </div>
                        @endforeach
                        
                        @if($chatLoading)
                            <div class="flex justify-start">
                                <div class="bg-white dark:bg-gray-700 rounded-lg px-2 py-1 border border-gray-200 dark:border-gray-600">
                                    <x-filament::loading-indicator class="h-3 w-3" />
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Input --}}
            <form wire:submit="sendChat" class="flex gap-1">
                <input
                    type="text"
                    wire:model="chatInput"
                    placeholder="Type a question..."
                    class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500 px-2 py-1"
                    @if($chatLoading) disabled @endif
                >
                <x-filament::button type="submit" size="sm" :disabled="$chatLoading">
                    <x-heroicon-o-paper-airplane class="w-4 h-4" />
                </x-filament::button>
            </form>
        @else
            <div class="text-center py-4">
                <x-heroicon-o-sparkles class="w-8 h-8 text-gray-400 mx-auto mb-2" />
                <p class="text-gray-500 text-sm">AI Assistant Unavailable</p>
                <p class="text-gray-400 text-xs mt-1">Configure Cloudflare AI to enable</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
