<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Review uncertain historical records here. Originals remain preserved. Confirmed documents appear in the linked vehicle profiles.
    </p>
    <dl class="flex flex-wrap gap-x-8 gap-y-3 text-sm">
        @foreach ($this->importCounts() as $label => $count)
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="font-semibold text-gray-950 dark:text-white">{{ number_format($count) }}</dd>
            </div>
        @endforeach
    </dl>
    {{ $this->table }}
</x-filament-panels::page>
