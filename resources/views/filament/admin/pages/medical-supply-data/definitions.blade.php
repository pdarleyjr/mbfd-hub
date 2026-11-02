@php $definitions = $overview['definitions'] ?? []; @endphp
<details class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" data-testid="supply-definitions">
    <summary class="min-h-[44px] cursor-pointer py-2 text-sm font-semibold text-gray-950 dark:text-white">What these numbers mean</summary>
    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $overview['label'] ?? $distributionNote }}. Dates and weeks are Eastern time.</p>
    @if ($definitions !== [])
        <dl class="supply-defs mt-3 text-sm text-gray-950 dark:text-white">
            @foreach ($definitions as $term => $sentence)
                <dt>{{ \Illuminate\Support\Str::headline((string) $term) }}</dt>
                <dd class="text-gray-700 dark:text-gray-300">{{ $sentence }}</dd>
            @endforeach
        </dl>
    @endif
</details>
