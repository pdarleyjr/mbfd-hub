@php
    /** @var list<array{key: string, label: string, count: int}> $tabs */
    /** @var string $active */
@endphp

<div class="px-4 py-3" data-defect-follow-up-tabs>
    <x-filament::tabs label="Defect follow-up stage">
        @foreach ($tabs as $tab)
            <x-filament::tabs.item
                :active="$active === $tab['key']"
                :badge="$tab['count']"
                wire:click="$set('followUpTab', '{{ $tab['key'] }}')"
                wire:key="defect-follow-up-tab-{{ $tab['key'] }}"
            >
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
</div>
