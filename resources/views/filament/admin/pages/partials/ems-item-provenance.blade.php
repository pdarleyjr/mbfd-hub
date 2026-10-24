@php
    $source = (array) ($item['source'] ?? []);
    $cells = [
        'Item as printed' => $source['item_cell_text'] ?? null,
        'Bag name' => $source['bag_alias_text'] ?? null,
        'Order level' => $source['order_level_text'] ?? null,
        'Quantity per package' => $source['qty_per_package_text'] ?? null,
        'Supplier line 1' => $source['primary_text'] ?? null,
        'Supplier line 2' => $source['secondary_text'] ?? null,
        'Supplier line 3' => $source['tertiary_text'] ?? null,
        'Item number' => $source['item_number_text'] ?? null,
        'Order amount' => $source['order_amount_text'] ?? null,
    ];
@endphp
<div class="space-y-4 text-sm" data-testid="item-provenance">
    @if ($source !== [])
        <div>
            <h3 class="font-semibold text-gray-950 dark:text-white">Where this row came from</h3>
            <p class="text-xs text-gray-600 dark:text-gray-400">Captain 5 supply room sheet, page {{ $source['page'] ?? '?' }}, row {{ $source['row'] ?? '?' }}. The cells below are the words on the sheet.</p>
            <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                @foreach ($cells as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-xs text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-gray-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </div>
    @else
        <p class="text-xs text-gray-600 dark:text-gray-400">This item was added by a person, so there is no sheet row to show.</p>
    @endif

    @if (! empty($item['suppliers']))
        <div>
            <h3 class="font-semibold text-gray-950 dark:text-white">Suppliers on the sheet</h3>
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $item['suppliers_note'] ?? 'Historical and unverified.' }}</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($item['suppliers'] as $supplier)
                    <li>{{ $supplier['raw'] ?? ($supplier['code'] ?? '') }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($item['review_reasons']))
        <div>
            <h3 class="font-semibold text-gray-950 dark:text-white">Why this row needs review</h3>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($item['review_reasons'] as $reason)
                    <li>{{ is_array($reason) ? ($reason['message'] ?? json_encode($reason)) : $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($item['audit']))
        <div>
            <h3 class="font-semibold text-gray-950 dark:text-white">Changes made by people</h3>
            <ul class="mt-1 space-y-1">
                @foreach ($item['audit'] as $change)
                    <li class="text-xs text-gray-700 dark:text-gray-300">{{ \App\Filament\Support\EmsLabels::et($change['created_at']) }}, {{ $change['actor_name'] }}: {{ str_replace('_', ' ', $change['field']) }} from {{ json_encode($change['before']) }} to {{ json_encode($change['after']) }}. Reason: {{ $change['reason'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
