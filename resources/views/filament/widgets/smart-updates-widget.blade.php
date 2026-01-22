<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <span class="text-xl">🤖</span>
                <span>AI Operations Hub</span>
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="refresh" size="sm" color="gray" icon="heroicon-o-arrow-path">
                Refresh
            </x-filament::button>
        </x-slot>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Instant Bullet Summary (Left 2 cols) --}}
            <div class="lg:col-span-2 space-y-3">
                @if($bulletSummary)
                    @foreach($bulletSummary as $key => $section)
                        @php
                            $colorMap = [
                                'red' => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',
                                'orange' => 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800 text-orange-800 dark:text-orange-300',
                                'yellow' => 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300',
                                'green' => 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',
                                'blue' => 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',
                                'purple' => 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-800 text-purple-800 dark:text-purple-300',
                            ];
                            $color = $section['color'] ?? 'gray';
                            $classes = $colorMap[$color] ?? 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-300';
                        @endphp
                        <div class="rounded-lg p-3 border {{ $classes }}">
                            <div class="flex items-center gap-2 font-semibold mb-1">
                                <span>{{ $section['icon'] ?? '•' }}</span>
                                <span>{{ $section['title'] }}</span>
                            </div>
                            <ul class="text-sm space-y-0.5 ml-6">
                                @foreach($section['items'] as $item)
                                    <li>• {{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @else
                    <div class="text-center py-8 text-gray-500">
                        <x-filament::loading-indicator class="h-6 w-6 mx-auto mb-2" />
                        Loading...
                    </div>
                @endif
            </div>

            {{-- Always-Visible Chat (Right col) --}}
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 flex flex-col h-80">
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">
                    💬 Ask AI
                </div>
                
                {{-- Messages --}}
                <div class="flex-1 overflow-y-auto p-3 space-y-2" id="chat-messages">
                    @forelse($chatMessages as $msg)
                        <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] px-3 py-1.5 rounded-lg text-sm {{ $msg['role'] === 'user' ? 'bg-primary-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100' }}">
                                {{ $msg['content'] }}
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 text-center py-4">
                            Ask about inventory, defects, projects...
                        </p>
                    @endforelse
                    @if($chatLoading)
                        <div class="flex justify-start">
                            <div class="bg-white dark:bg-gray-700 px-3 py-2 rounded-lg">
                                <x-filament::loading-indicator class="h-4 w-4" />
                            </div>
                        </div>
                    @endif
                </div>
                
                {{-- Input --}}
                <form wire:submit.prevent="sendChat" class="p-2 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex gap-2">
                        <input
                            type="text"
                            wire:model="chatInput"
                            placeholder="Type a question..."
                            class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1.5"
                            autocomplete="off"
                        />
                        <button type="submit" class="px-3 py-1.5 bg-primary-600 text-white rounded-lg text-sm hover:bg-primary-700" {{ $chatLoading ? 'disabled' : '' }}>
                            Send
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="text-xs text-gray-400 mt-2 text-right">
            Auto-refreshes on data changes
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
