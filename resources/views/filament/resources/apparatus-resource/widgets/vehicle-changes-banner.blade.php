<x-filament-widgets::widget>
    @if ($changes !== [])
        <x-filament::section
            icon="heroicon-o-arrows-right-left"
            icon-color="warning"
            heading="Vehicle changes"
            description="A crew changed trucks. Nobody needs to approve it. Acknowledge it once you have seen it."
            data-vehicle-changes-banner
        >
            <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($changes as $change)
                    <li wire:key="vehicle-change-{{ $change['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $change['line'] }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $change['old_truck'] }}</p>
                            @foreach ($change['review'] as $note)
                                <p class="text-sm font-medium text-warning-700 dark:text-warning-400">{{ $note }}</p>
                            @endforeach
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($canAcknowledge)
                                <x-filament::button size="sm" wire:click="acknowledge({{ $change['id'] }})" wire:loading.attr="disabled">Acknowledge</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="gray" tag="a" :href="$change['url']">View</x-filament::button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
