<x-filament-panels::page>
    <div class="max-w-lg mx-auto">
        <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-sm">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-semibold">Password Change Required</p>
                    <p class="mt-1">For your security, you must set a personal password before accessing the Employee Portal. Your password must be at least 8 characters and include uppercase, lowercase, and numbers.</p>
                </div>
            </div>
        </div>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" size="lg" class="w-full">
                    Set Password & Continue
                </x-filament::button>
            </div>
        </form>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
