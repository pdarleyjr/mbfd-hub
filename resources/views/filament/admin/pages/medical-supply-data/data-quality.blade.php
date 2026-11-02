@use('App\Filament\Support\SupplyDataLabels', 'L')
@php
    $quality = $overview['data_quality'] ?? [];
    $checks = [
        ['estimated_items', 'Estimated balances', 'Supplies whose balance is a best guess, not a count.'],
        ['uncounted_items', 'Never counted', 'Supplies with no count or estimate, so the quantity is unknown.'],
        ['unresolved_conversions', 'Unclear package size', 'Supplies counted by the box or case with no stated size; shown in single units.'],
        ['negative_balances', 'Below-zero balances', 'Supplies whose books show less than none. Usually a missed receipt or a miscount.'],
        ['items_without_minimum', 'No minimum set', 'Supplies with no minimum level, so they can never read as low.'],
        ['unattributed_movements', 'Issues not linked to a request', 'Stock that left the room with no request, so no unit is credited.'],
        ['movements_on_uncounted_requests', 'Issues on cancelled or replaced requests', 'Stock that left even though the request no longer counts. Included in distributed.'],
        ['requests_without_unit', 'Requests with no unit', 'Requests whose truck is no longer on file.'],
        ['trucks_with_more_than_one_unit_name', 'Trucks with two unit names', 'A changeout moved the unit; requests stay with the truck they named.'],
    ];
    $suspect = array_values($quality['estimates_far_above_order_level'] ?? []);
    $notes = array_values($quality['notes'] ?? []);
    $insufficient = array_values($quality['insufficient_data'] ?? []);
@endphp
<x-filament::section heading="Data quality" description="Where these figures are thinner than they look, so nobody reads more into them than is there." collapsible collapsed>
    @if ($notes !== [] || $insufficient !== [])
        <ul class="mb-4 list-disc space-y-1 pl-5 text-sm text-gray-950 dark:text-white" data-testid="data-quality-notes">
            @foreach ($insufficient as $entry)<li>{{ $entry['message'] ?? '' }}</li>@endforeach
            @foreach ($notes as $note)<li>{{ $note }}</li>@endforeach
        </ul>
    @endif
    <dl class="supply-stats" data-testid="supply-data-quality">
        @foreach ($checks as [$key, $label, $meaning])
            @php $value = $quality[$key] ?? null; @endphp
            <div class="supply-stat" wire:key="quality-{{ $key }}">
                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</dt>
                <dd class="supply-stat-value text-gray-950 dark:text-white">{{ L::number($value) }}@if ($value === null)<span class="sr-only">Not available</span>@endif</dd>
                <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $meaning }}</dd>
            </div>
        @endforeach
    </dl>
    @if ($suspect !== [])
        <h3 class="mt-4 text-sm font-semibold text-gray-950 dark:text-white">Estimates that look too high</h3>
        <p class="text-xs text-gray-600 dark:text-gray-400">These best-guess balances are many times the supply's order level. Count them to replace the guess.</p>
        <ul class="mt-2 space-y-1 text-sm text-gray-950 dark:text-white" data-testid="suspect-estimates">
            @foreach ($suspect as $row)
                <li wire:key="suspect-{{ $row['item_id'] }}">{{ $row['name'] }}: {{ L::number($row['on_hand'] ?? null) }} {{ L::unitWord($row['count_unit'] ?? null) }}, about {{ L::number($row['times_order_level'] ?? null) }} times its order level</li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
