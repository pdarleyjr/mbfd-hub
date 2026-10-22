<x-filament-panels::page>
    @include('filament.pages.partials.personnel-table-styles')
    <p class="text-sm text-gray-600">
        Profiles come from the Hub employee roster. Shift, A-Day, unit and bid position reflect the current published bid; an unrecorded assignment stays blank. Use the profile action for protected identity, email or employment changes.
    </p>
    <div wire:offline class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="status">Connection interrupted. Keep this page open and reconnect before saving.</div>
    {{ $this->table }}
</x-filament-panels::page>
