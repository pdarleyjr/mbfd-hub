@use('App\Filament\Support\SupplyDataLabels', 'L')
@php
    $trend = array_values($overview['trend'] ?? []);
    $monthly = isset($trend[0]['month_start']);
    $startKey = $monthly ? 'month_start' : 'week_start';
    $bucketWord = $monthly ? 'Month starting' : 'Week starting';
    $count = count($trend);
    $hasActivity = collect($trend)->contains(fn (array $bucket): bool => (int) ($bucket['distribution_events'] ?? 0) > 0 || (int) ($bucket['requests'] ?? 0) > 0);
    // Chart geometry only: the bars are drawn from the report's numbers, scaled to the tallest one.
    $width = 480; $height = 220; $left = 30; $top = 12; $bottom = 30;
    $plotWidth = $width - $left - 6; $plotHeight = $height - $top - $bottom;
    $tallest = max(1, ...array_map(fn (array $bucket): int => max((int) ($bucket['distribution_events'] ?? 0), (int) ($bucket['requests'] ?? 0)), $trend ?: [[]]));
    $slot = $count > 0 ? $plotWidth / $count : $plotWidth;
    $barWidth = max(3, min(16, $slot * 0.36));
    $labelEvery = max(1, (int) ceil($count / 6));
    $trendNotes = collect($overview['data_quality']['insufficient_data'] ?? [])->where('section', 'trend')->pluck('message')->all();
@endphp
<x-filament::section :heading="$monthly ? 'Month by month' : 'Week by week'" description="Distribution events and requests over the selected period. Weeks start on Sunday, Eastern time.">
    @foreach ($trendNotes as $note)
        <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white" data-testid="supply-trend-note">{{ $note }}</p>
    @endforeach
    @if (! $hasActivity)
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="supply-trend-empty">
            Nothing was distributed or requested in this period, so there is no trend to draw. Choose a longer time period to see more.
        </p>
    @else
        <p class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-gray-300" aria-hidden="true">
            <span><span class="supply-key supply-key-events"></span>Distribution events</span>
            <span><span class="supply-key supply-key-requests"></span>Requests submitted</span>
        </p>
        <svg class="supply-chart text-gray-700 dark:text-gray-300" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-labelledby="supply-trend-title supply-trend-desc" data-testid="supply-trend-chart" focusable="false">
            <title id="supply-trend-title">{{ $monthly ? 'Distribution events and requests by month' : 'Distribution events and requests by week' }}</title>
            <desc id="supply-trend-desc">Paired bars for each {{ $monthly ? 'month' : 'week' }}: distribution events and requests submitted. {{ $distributionNote }}. The same numbers are in the table below the chart.</desc>
            <line class="supply-axis" x1="{{ $left }}" y1="{{ $top + $plotHeight }}" x2="{{ $width - 4 }}" y2="{{ $top + $plotHeight }}" />
            <line class="supply-axis" x1="{{ $left }}" y1="{{ $top }}" x2="{{ $left }}" y2="{{ $top + $plotHeight }}" />
            <text x="{{ $left - 4 }}" y="{{ $top + 5 }}" text-anchor="end">{{ $tallest }}</text>
            <text x="{{ $left - 4 }}" y="{{ $top + $plotHeight }}" text-anchor="end">0</text>
            @foreach ($trend as $index => $bucket)
                @php
                    $events = (int) ($bucket['distribution_events'] ?? 0);
                    $requests = (int) ($bucket['requests'] ?? 0);
                    $x = $left + $slot * $index + ($slot - 2 * $barWidth - 2) / 2;
                    $eventsHeight = $plotHeight * $events / $tallest;
                    $requestsHeight = $plotHeight * $requests / $tallest;
                    $name = L::bucket((string) ($bucket[$startKey] ?? ''), $monthly);
                @endphp
                <g wire:key="trend-{{ $index }}">
                    <rect class="supply-bar-events" x="{{ round($x, 1) }}" y="{{ round($top + $plotHeight - $eventsHeight, 1) }}" width="{{ round($barWidth, 1) }}" height="{{ round($eventsHeight, 1) }}"><title>{{ $bucketWord }} {{ $name }}: {{ $events }} distribution {{ $events === 1 ? 'event' : 'events' }}</title></rect>
                    <rect class="supply-bar-requests" x="{{ round($x + $barWidth + 2, 1) }}" y="{{ round($top + $plotHeight - $requestsHeight, 1) }}" width="{{ round($barWidth, 1) }}" height="{{ round($requestsHeight, 1) }}"><title>{{ $bucketWord }} {{ $name }}: {{ $requests }} {{ $requests === 1 ? 'request' : 'requests' }}</title></rect>
                    @if ($index % $labelEvery === 0)
                        <text x="{{ round($x + $barWidth, 1) }}" y="{{ $height - 10 }}" text-anchor="middle">{{ $name }}</text>
                    @endif
                </g>
            @endforeach
        </svg>
    @endif

    @if ($count > 0)
        <details class="mt-3" data-testid="supply-trend-table">
            <summary class="min-h-[44px] cursor-pointer py-2 text-sm font-medium text-gray-950 dark:text-white">Show the numbers behind the chart</summary>
            <table data-hub-table class="w-full text-left text-sm">
                <caption class="sr-only">Distribution events and requests by {{ $monthly ? 'month' : 'week' }}</caption>
                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium first:pl-0">{{ $bucketWord }}</th>
                        <th scope="col" class="supply-num px-3 py-2 font-medium">Distribution events</th>
                        <th scope="col" class="supply-num px-3 py-2 font-medium">Requests submitted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($trend as $index => $bucket)
                        <tr wire:key="trend-row-{{ $index }}">
                            <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ L::bucket((string) ($bucket[$startKey] ?? ''), $monthly) }}</th>
                            <td class="supply-num px-3 py-2">{{ L::number($bucket['distribution_events'] ?? null) }}</td>
                            <td class="supply-num px-3 py-2">{{ L::number($bucket['requests'] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</x-filament::section>
