<x-filament-panels::page>
    <div class="mx-auto max-w-lg">
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <p class="font-semibold">Password Change Required</p>
            <p class="mt-1">Set a new password before continuing to use this panel.</p>
        </div>

        <x-filament-panels::form id="form" wire:submit="save">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" size="lg" class="w-full">
                    Set Password &amp; Continue
                </x-filament::button>
            </div>
        </x-filament-panels::form>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
