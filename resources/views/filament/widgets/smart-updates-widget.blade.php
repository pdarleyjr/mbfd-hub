<x-filament-widgets::widget>
    <div class="mbfd-admin-ai" x-data="{ expanded: $wire.entangle('isExpanded') }" x-id="['admin-ai-chat', 'admin-ai-question']" wire:poll.120s="refreshTick">
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2 command-center-heading">
                    <x-heroicon-o-command-line class="w-5 h-5 mbfd-admin-ai-brand-icon" aria-hidden="true" />
                    Command Center
                </div>
            </x-slot>

            <x-slot name="headerEnd">
                <button
                    type="button"
                    @click="expanded = !expanded"
                    :aria-expanded="expanded.toString()"
                    :aria-controls="$id('admin-ai-chat')"
                    class="mbfd-admin-ai-toggle">
                    <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                    <x-heroicon-o-chevron-down
                        class="w-4 h-4"
                        aria-hidden="true"
                        x-bind:style="expanded ? 'transform: rotate(180deg)' : ''" />
                </button>
            </x-slot>

            <div>
                {{-- Collapsed State - Bullet Summary --}}
                <div x-show="!expanded" class="space-y-3">
                    {{-- AI Brief — regenerated only when operational data changes --}}
                    @if($aiSummary)
                        <div class="command-center-section mbfd-admin-ai-brief">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="command-center-badge-info">AI Brief</span>
                                @if($aiSummaryAt)
                                    <span class="mbfd-admin-ai-meta" title="{{ $aiSummaryAt }}">
                                        updated {{ \Illuminate\Support\Carbon::parse($aiSummaryAt)->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            @if(isset($aiSummary['raw_response']))
                                <p class="command-center-item whitespace-pre-wrap">{{ $aiSummary['raw_response'] }}</p>
                            @else
                                <ul class="space-y-0.5 ml-1">
                                    @foreach($aiSummary as $points)
                                        @if(is_array($points))
                                            @foreach($points as $point)
                                                <li class="command-center-item">• {{ $point }}</li>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

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
                                        <span class="mbfd-admin-ai-meta">
                                            {{ count($section['items']) }} {{ count($section['items']) === 1 ? 'item' : 'items' }}
                                        </span>
                                    </div>
                                    @if(in_array($key, ['defects', 'shop_work']))
                                        <a href="{{ $key === 'defects' ? '/admin/defects' : '/admin/shop-works' }}"
                                           class="mbfd-admin-ai-link">
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
                        <p class="mbfd-admin-ai-meta" role="status">Loading summary…</p>
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
                <div x-show="expanded" x-cloak :id="$id('admin-ai-chat')" class="space-y-3">
                    <div class="mbfd-admin-ai-conversation dashboard-widget-scrollable" role="log" aria-label="AI assistant conversation" aria-live="polite" aria-relevant="additions" aria-busy="{{ $chatLoading ? 'true' : 'false' }}" tabindex="0">
                        @if(empty($chatMessages))
                            <div class="mbfd-admin-ai-empty">
                                <p class="font-medium mb-2">Ask the assistant about:</p>
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
                                    <div class="mbfd-admin-ai-message" data-role="{{ $msg['role'] === 'user' ? 'user' : 'assistant' }}">
                                        <p class="whitespace-pre-wrap">{{ $msg['content'] }}</p>
                                        <span class="mbfd-admin-ai-message-time">{{ $msg['time'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        @endif

                        @if($chatLoading)
                            <div class="flex justify-start mb-2">
                                <div class="mbfd-admin-ai-message mbfd-admin-ai-loading" role="status">
                                    <x-filament::loading-indicator class="h-4 w-4" aria-hidden="true" />
                                    <span>Preparing a response…</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <form wire:submit="sendChat" class="mbfd-admin-ai-form">
                        <label :for="$id('admin-ai-question')" class="sr-only">Question or request for the AI assistant</label>
                        <input
                            :id="$id('admin-ai-question')"
                            type="text"
                            wire:model="chatInput"
                            placeholder="Type a question or request…"
                            class="mbfd-admin-ai-input"
                            @if($chatLoading) disabled @endif
                        >
                        <x-filament::button type="submit" size="sm" :disabled="$chatLoading" aria-label="Send message to the AI assistant">
                            <x-heroicon-o-paper-airplane class="w-4 h-4" aria-hidden="true" />
                            <span>Send</span>
                        </x-filament::button>
                    </form>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
