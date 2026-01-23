<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Priority Notifications & Action Items
        </x-slot>

        <x-slot name="description">
            AI-prioritized alerts requiring attention
        </x-slot>

        <div class="space-y-3">
            @php
                $notifications = $this->getNotifications();
            @endphp

            @if(count($notifications) > 0)
                @foreach($notifications as $notification)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 space-y-2">
                                <div class="flex items-center gap-2">
                                    @if($notification['priority'] === 'critical')
                                        <x-filament::badge color="danger" icon="heroicon-o-exclamation-triangle">
                                            Critical
                                        </x-filament::badge>
                                    @elseif($notification['priority'] === 'high')
                                        <x-filament::badge color="warning" icon="heroicon-o-exclamation-circle">
                                            High Priority
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge color="info" icon="heroicon-o-information-circle">
                                            Medium Priority
                                        </x-filament::badge>
                                    @endif

                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $notification['title'] }}
                                    </h4>
                                </div>

                                <div class="text-sm">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">
                                        {{ $notification['project_name'] }}
                                    </span>
                                </div>

                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $notification['message'] }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2">
                                <x-filament::button
                                    tag="a"
                                    href="{{ $notification['action_url'] }}"
                                    size="xs"
                                    color="primary"
                                >
                                    View Details
                                </x-filament::button>

                                @if($notification['type'] === 'overdue_milestone')
                                    <x-filament::button
                                        wire:click="markComplete('{{ $notification['id'] }}')"
                                        size="xs"
                                        color="success"
                                    >
                                        Mark Complete
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    wire:click="snooze('{{ $notification['id'] }}')"
                                    size="xs"
                                    color="gray"
                                    outlined
                                >
                                    Snooze
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="text-center py-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success-50 dark:bg-success-900/20 mb-4">
                        <x-filament::icon
                            icon="heroicon-o-check-circle"
                            class="w-8 h-8 text-success-600 dark:text-success-400"
                        />
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">
                        All caught up!
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No priority notifications at this time.
                    </p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
