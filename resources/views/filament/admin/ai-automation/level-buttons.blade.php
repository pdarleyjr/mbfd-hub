@php
    /** @var \App\Models\SupportTriage\SupportEquipmentRule $record */
    $record = $getRecord();
    $current = $record->level;
    $styles = [
        'low' => ['Low', 'bg-success-600 text-white border-success-600', 'border-gray-300 text-gray-700 hover:bg-success-50 dark:border-white/20 dark:text-gray-200'],
        'medium' => ['Medium', 'bg-warning-600 text-white border-warning-600', 'border-gray-300 text-gray-700 hover:bg-warning-50 dark:border-white/20 dark:text-gray-200'],
        'high' => ['High', 'bg-danger-600 text-white border-danger-600', 'border-gray-300 text-gray-700 hover:bg-danger-50 dark:border-white/20 dark:text-gray-200'],
    ];
@endphp
<div class="flex gap-1 px-3 py-2" role="group" aria-label="Priority for {{ $record->name }}">
    @foreach ($styles as $level => [$label, $on, $off])
        <button
            type="button"
            wire:click="setRuleLevel({{ $record->getKey() }}, '{{ $level }}')"
            wire:loading.attr="disabled"
            aria-pressed="{{ $current === $level ? 'true' : 'false' }}"
            class="min-h-[2.5rem] min-w-[4.25rem] rounded-lg border px-3 text-sm font-semibold transition {{ $current === $level ? $on : $off }}"
        >{{ $label }}</button>
    @endforeach
</div>
