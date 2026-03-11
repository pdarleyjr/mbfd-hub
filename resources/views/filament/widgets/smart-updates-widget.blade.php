<x-filament-widgets::widget>
    <div x-data="{ expanded: $wire.entangle('isExpanded') }">
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2 command-center-heading">
                    <x-heroicon-o-command-line class="w-5 h-5" style="color: #B91C1C;" />
                    Command Center
                </div>
            </x-slot>

            <x-slot name="headerEnd">
                <button
                    @click="expanded = !expanded"
                    class="text-sm font-medium flex items-center gap-1"
                    style="color: #B91C1C;">
                    <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                    <x-heroicon-o-chevron-down
                        class="w-4 h-4"
                        style="transition: transform 200ms cubic-bezier(0.16, 1, 0.3, 1);"
                        x-bind:style="expanded ? 'transform: rotate(180deg)' : ''" />
                </button>
            </x-slot>

            <div>
                {{-- Collapsed State - Bullet Summary --}}
                <div x-show="!expanded" class="space-y-3">
                    @if($bulletSummary)
                        @foreach($bulletSummary as $key => $section)
                            @php
                                $badgeClass = match($section['color']) {
                                    'red' => 'command-center-badge-critical',
                                    'orange', 'yellow' => 'command-center-badge-warn',
                                    'blue', 'purple' => 'command-center-badge-info',
                                    default => 'command-center-badge-ok',
                                };
                            @endphp
                            <div class="command-center-section">
                                <div class="flex items-center justify-between mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="{{ $badgeClass }}">
                                            {{ $section['icon'] }} {{ $section['title'] }}
                                        </span>
                                        <span class="text-xs" style="color: #A8A29E;">
                                            {{ count($section['items']) }} {{ count($section['items']) === 1 ? 'item' : 'items' }}
                                        </span>
                                    </div>
                                    @if(in_array($key, ['defects', 'shop_work']))
                                        <a href="{{ $key === 'defects' ? '/admin/apparatus-defects' : '/admin/shop-works' }}"
                                           class="text-xs font-medium" style="color: #B91C1C;">
                                            View All →
                                        </a>
                                    @endif
                                </div>
                                <ul class="space-y-0.5 ml-1">
                                    @foreach(array_slice($section['items'], 0, 5) as $item)
                                        <li class="command-center-item">• {{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @else
                        <p class="text-sm" style="color: #78716C;">Loading summary...</p>
                    @endif

                    <div class="command-center-divider"></div>
                    <x-filament::button
                        @click="expanded = true"
                        size="sm"
                        color="primary"
                        class="w-full">
                        <x-heroicon-o-sparkles class="w-4 h-4 mr-1" />
                        Ask AI Assistant
                    </x-filament::button>
                </div>

                {{-- Expanded State - Chat Interface --}}
                <div x-show="expanded" x-cloak class="space-y-3">
                    <div class="rounded-lg p-3 min-h-[200px] max-h-[400px] overflow-y-auto dashboard-widget-scrollable"
                         style="background-color: #FAFAF8; border: 1px solid #E8E5E0;">
                        @if(empty($chatMessages))
                            <div class="text-xs text-center py-4" style="color: #A8A29E;">
                                <p class="font-medium mb-2" style="color: #57534E;">Ask me anything about:</p>
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
                                    <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                         style="{{ $msg['role'] === 'user'
                                             ? 'background-color: #B91C1C; color: #fff;'
                                             : 'background-color: #fff; color: #44403C; border: 1px solid #E8E5E0;' }}">
                                        <p class="whitespace-pre-wrap">{{ $msg['content'] }}</p>
                                        <span class="block mt-1" style="font-size: 10px; opacity: 0.6;">{{ $msg['time'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        @endif

                        @if($chatLoading)
                            <div class="flex justify-start mb-2">
                                <div class="rounded-lg px-3 py-2" style="background-color: #fff; border: 1px solid #E8E5E0;">
                                    <x-filament::loading-indicator class="h-4 w-4" />
                                </div>
                            </div>
                        @endif
                    </div>

                    <form wire:submit="sendChat" class="flex gap-2">
                        <input
                            type="text"
                            wire:model="chatInput"
                            placeholder="Type your question or request..."
                            class="flex-1 text-sm rounded-lg"
                            style="border-color: #D4D0CA; color: #292524; background-color: #fff;"
                            @if($chatLoading) disabled @endif
                        >
                        <x-filament::button type="submit" size="sm" :disabled="$chatLoading">
                            <x-heroicon-o-paper-airplane class="w-4 h-4" />
                        </x-filament::button>
                    </form>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
