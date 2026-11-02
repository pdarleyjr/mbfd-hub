@use('App\Filament\Support\SupplyDataLabels', 'L')
@php
    $fulfillment = $overview['fulfillment'] ?? [];
    $rows = [
        ['submitted', 'Submitted', 'Requests sent in this period.'],
        ['acknowledged', 'Acknowledged', 'Requests Captain 5 has acknowledged.'],
        ['rejected', 'Rejected', 'Requests Captain 5 has rejected.'],
        ['corrected', 'Corrected', 'Requests the member re-sent with a correction.'],
        ['reconciled', 'Reconciled', 'Requests deducted from the supply room and filed.'],
        ['open', 'Still open', 'Requests with work left to do.'],
        ['qty_requested', 'Quantity requested', 'Total asked for on counted requests, in each supply\'s count unit.'],
        ['qty_distributed', 'Quantity distributed', 'Total issued for those requests. '.$distributionNote.'.'],
    ];
    $median = $fulfillment['median_hours_to_reconcile'] ?? null;
@endphp
<x-filament::section heading="Request fulfillment" description="How requests moved through Captain 5 in this period." collapsible collapsed>
    <dl class="supply-stats" data-testid="supply-fulfillment">
        @foreach ($rows as [$key, $label, $meaning])
            <div class="supply-stat" wire:key="fulfillment-{{ $key }}">
                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($fulfillment[$key] ?? null) }}@if (($fulfillment[$key] ?? null) === null)<span class="sr-only">Not available</span>@endif</dd>
                <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $meaning }}</dd>
            </div>
        @endforeach
        <div class="supply-stat" data-testid="fulfillment-median">
            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Typical time to reconcile</dt>
            <dd class="supply-stat-value text-gray-950 dark:text-white">
                {{ $median === null ? L::DASH : L::number($median).' hours' }}
                @if ($median === null)<span class="sr-only">Not available</span>@endif
            </dd>
            <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                {{ $median === null ? 'No request was reconciled in this period, so there is nothing to measure.' : 'The middle value, from submission to reconciliation.' }}
            </dd>
        </div>
    </dl>
</x-filament::section>
