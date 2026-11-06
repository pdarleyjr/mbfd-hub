@php
    // Short names for the problem kinds (App\Services\Fleet\FrontlineCoverage::KIND_*), shown on the unit chips.
    $kindLabels = [
        'several_trucks' => 'On 2+ trucks',
        'conflicting_identity' => 'Unit fields disagree',
        'no_truck' => 'No truck',
        'truck_not_in_service' => 'Not In Service',
    ];
    $several = count($problems) > 1;
@endphp

<x-filament-widgets::widget>
    @if ($problems !== [])
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="danger"
            :heading="count($problems) === 1 ? 'A front-line unit needs a truck' : count($problems).' front-line units need attention'"
            description="Every front-line unit must be on one truck, and that truck must be In Service. The Daily Checkout deploy check fails until this is fixed."
            data-frontline-coverage-banner
        >
            @if ($several)
                {{-- Several units: one chip per unit; the full instructions are one tap away (open when there are only a few). --}}
                <ul role="list" class="hub-frontline__units" aria-label="Front-line units that need attention">
                    @foreach ($problems as $problem)
                        @php($kindLabel = $kindLabels[$problem['kind']] ?? 'Needs attention')
                        <li wire:key="frontline-chip-{{ $problem['unit_id'] }}">
                            @if ($problem['url'] && ! $onProfile)
                                <a href="{{ $problem['url'] }}" class="hub-frontline__chip" aria-label="{{ $problem['unit_id'] }}: {{ $kindLabel }}. Open truck">
                                    <strong>{{ $problem['unit_id'] }}</strong>
                                    <span>{{ $kindLabel }}</span>
                                </a>
                            @else
                                <span class="hub-frontline__chip">
                                    <strong>{{ $problem['unit_id'] }}</strong>
                                    <span>{{ $kindLabel }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <details class="hub-frontline__details" @if (count($problems) <= 3) open @endif>
                    <summary>
                        What to fix for each unit
                        <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
                    </summary>
            @endif

            <ul role="list" @class(['divide-y divide-gray-200 dark:divide-white/10', 'mt-1' => $several])>
                @foreach ($problems as $problem)
                    <li wire:key="frontline-{{ $problem['unit_id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" data-frontline-problem="{{ $problem['kind'] }}">
                        <p class="min-w-0 flex-1 text-sm text-gray-950 dark:text-white">{{ $problem['message'] }}</p>
                        @if ($problem['url'] && ! $onProfile)
                            <x-filament::button size="sm" color="gray" tag="a" :href="$problem['url']">Open truck</x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($several)
                </details>
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
