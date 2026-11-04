<x-filament-panels::page>
    @php($items = $this->items())
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <label class="block text-sm sm:w-80">
            <span class="font-medium text-gray-700 dark:text-gray-200">Search</span>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="for example, flashlight" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </label>
        <a href="{{ \App\Filament\Resources\SupportEquipmentRuleResource::getUrl('index') }}" class="text-sm font-medium text-primary-600 underline dark:text-primary-400">Back to Equipment Priorities</a>
    </div>

    @if ($items === [])
        <div class="rounded-lg border border-success-300 bg-success-50 p-4 text-sm text-success-800 dark:border-success-500/40 dark:bg-success-500/10 dark:text-success-300" role="status">
            @if ($search !== '')
                Nothing here matches that search.
            @else
                Every checklist item has a priority. Nice work.
            @endif
        </div>
    @else
        <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white dark:divide-white/10 dark:border-white/10 dark:bg-white/5">
            @foreach ($items as $item)
                <li wire:key="item-{{ md5($item['name']) }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $item['name'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            On {{ collect($item['apparatus_types'])->map(fn ($type) => $this->truckLabels()[$type] ?? $type)->implode(', ') }}
                            @if ($item['occurrences'] > 1) · listed {{ $item['occurrences'] }} times @endif
                        </p>
                    </div>
                    <div class="flex gap-2" role="group" aria-label="Choose a priority for {{ $item['name'] }}">
                        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $level => $label)
                            <button
                                type="button"
                                wire:click="classify({{ \Illuminate\Support\Js::from($item['name']) }}, '{{ $level }}')"
                                wire:loading.attr="disabled"
                                class="min-h-[2.75rem] min-w-[5rem] rounded-lg border px-4 text-sm font-semibold transition
                                    {{ $level === 'low' ? 'border-success-600 text-success-700 hover:bg-success-50 dark:text-success-400' : '' }}
                                    {{ $level === 'medium' ? 'border-warning-600 text-warning-700 hover:bg-warning-50 dark:text-warning-400' : '' }}
                                    {{ $level === 'high' ? 'border-danger-600 text-danger-700 hover:bg-danger-50 dark:text-danger-400' : '' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
