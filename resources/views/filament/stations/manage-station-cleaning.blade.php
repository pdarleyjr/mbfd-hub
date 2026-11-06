<x-filament-panels::page>
    <x-filament-panels::form id="station-cleaning-form" wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="false"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
