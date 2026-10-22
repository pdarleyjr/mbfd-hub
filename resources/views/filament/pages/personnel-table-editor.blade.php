<x-filament-panels::page>
    @include('filament.pages.partials.personnel-table-styles')
    <div wire:offline class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="status">Connection interrupted. Keep this page open and reconnect before saving.</div>
    {{ $this->table }}
</x-filament-panels::page>
