<x-filament-widgets::widget>
    @if ($problems !== [])
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="danger"
            :heading="count($problems) === 1 ? 'A front-line unit needs a truck' : count($problems).' front-line units need attention'"
            description="Every front-line unit must be on one truck, and that truck must be In Service. The Daily Checkout deploy check fails until this is fixed."
            data-frontline-coverage-banner
        >
            <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($problems as $problem)
                    <li wire:key="frontline-{{ $problem['unit_id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" data-frontline-problem="{{ $problem['kind'] }}">
                        <p class="min-w-0 flex-1 text-sm text-gray-950 dark:text-white">{{ $problem['message'] }}</p>
                        @if ($problem['url'] && ! $onProfile)
                            <x-filament::button size="sm" color="gray" tag="a" :href="$problem['url']">Open truck</x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
